<?php
namespace exface\Core\Behaviors;

use exface\Core\CommonLogic\DataSheets\DataCheckWithOutputData;
use exface\Core\CommonLogic\Debugger\LogBooks\BehaviorLogBook;
use exface\Core\CommonLogic\Model\Behaviors\AbstractValidatingBehavior;
use exface\Core\CommonLogic\Model\Behaviors\BehaviorDataCheckList;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Events\DataSheet\OnBeforeUpdateDataEvent;
use exface\Core\Events\DataSheet\OnCreateDataEvent;
use exface\Core\Events\DataSheet\OnUpdateDataEvent;
use exface\Core\Exceptions\DataSheets\DataCheckFailedErrorMultiple;
use exface\Core\Interfaces\DataSheets\DataCheckListInterface;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Interfaces\Events\DataSheetEventInterface;
use exface\Core\Interfaces\Events\EventInterface;
use exface\Core\Interfaces\Model\BehaviorInterface;

/**
 * Applies a checklist to the input data and persists the results at a configurable data address.
 * 
 * This behavior is similar to the `ValidatingBehavior` except for the result of the checks: in contrast to the
 *  `ValidatingBehavior`, that produces errors if at least one condition was matched, the `ChecklistingBehavior` merely
 * saves its findings to the data source allowing you to process them later.
 * 
 * Depending on your configuration this behavior reacts to `OnCreateData`, `OnUpdateData` or both. This means
 * it always triggers after the respective transaction has been completed. This is to ensure that all data necessary
 * for generating the output data is available and up-to-date.
 * 
 * Once triggered, the behavior runs all its data checks for that event. Whenever a data-item matches any of
 * those data checks, that data check will output a checklist item. Checklist items can be warnings, hints, 
 * errors - anything, that is not critical, but important to see for the user.
 * 
 * Checklist items generated by this behavior will be saved to whatever table you specified in the configuration.
 * Ideally that table  resides in the same data source as the checked object. See below for more details on how to
 * create this table.
 * 
 * You can think of this behavior as "taking notes" about state of your data. In this metaphor the output table is your
 * notebook.  You are responsible for the quality of the information in it as well as its structure. As such you should
 * view your definition of the output sheet as the actual notes you will be taking. This becomes especially potent,
 * once you start using placeholders as values in your output sheet. You could for instance persist a complex relation
 * to make your future work easier (e.g. `"Product":"[#ORDER_POS__PRODUCT#]"`).
 * 
 * ## Setup
 * 
 * 1. Create a new table for the app this behavior belongs to. It will serve as persistent storage for the output
 * data this behavior generates. This table needs to fulfill the following conditions:
 * 
 *      - It must have a matching column for each column defined in the `rows` property of `output_data_sheet`
 * (matching name and datatype).
 *      - It must have the default system columns of your respective app (e.g. `id`, `modified_by`, etc.).
 *      - It must have a column that matches your `affected_uid_alias`, both in name and datatype. 
 *      - You can find an example definition in the section `Examples`.
 * 
 * 2. Create a MetaObject for this table that **inherits from its BaseObject**. 
 * 
 * 3. If your data source is derived from LogBase, you need to add a LogBase-Class to the Data Source Settings of the
 * newly created MetaObject, for example:
 * `{"LOGBASE_CLASS":"ScaLink.OneLink.LieferscheinPosStatus"}` 
 * 
 * 4. Then, attach a new `ChecklistingBehavior` to the MetaObject that you actually wish to modify (for example the
 * OrderPosition) and configure the behavior as needed.
 * 
 * 5. If properly configured, the behavior will now write its output to the table you have created whenever its
 * conditions are met. You can now read said data from the table to create useful effects, such as rendering
 * notifications.
 * 
 * ## Placeholders
 * 
 * This behavior supports basic data placeholders. Depending on the event context, it may even be able to access
 * pre-transaction:
 * 
 * - `[#~new:attribute_alias#]`: Access post-transaction data. This placeholder is available in all event contexts.
 * - `[#~old:attribute_alias#]`: Access pre-transaction data, i.e. the data before it was modified. This placeholder is
 * only available for `check_on_update`. If  you try to use it in `check_always` or check_on_create` the behavior will
 * throw an error.
 * 
 * ## Examples
 * 
 * ### Example SQL for an Output Table 
 * 
 * ```
 * 
 *  CREATE TABLE [dbo].[CHECKLIST] (
 *      [id] bigint NOT NULL,
 *      [ZeitNeu] datetime NOT NULL,
 *      [ZeitAend] datetime NOT NULL,
 *      [UserNeu] nvarchar(50) NOT NULL,
 *      [UserAend] nvarchar(50) NOT NULL,
 *      [Betreiber] nvarchar(8) NOT NULL,
 *      [CRITICALITY] int NOT NULL,
 *      [LABEL] nvarchar(50) NOT NULL,
 *      [MESSAGE] nvarchar(100) NOT NULL,
 *      [COLOR] nvarchar(20) NOT NULL,
 *      [ICON] nvarchar(100) NOT NULL,
 *      [AFFECTED_UID] int NOT NULL
 *  );
 * 
 * ```
 * 
 * ### Example UXON Definition with one DataCheck
 * 
 * ```
 *  {
 *      "check_on_update": [{
 *          "affected_uid_alias": "AFFECTED_UID"
 *          "output_data_sheet": {
 *              "object_alias": "my.APP.CHECKLIST",
 *              "rows": [{
 *                  "CRITICALITY": "0",
 *                  "LABEL": "Error",
 *                  "MESSAGE": "This order includes products, that are not available for ordering yet!",
 *                  "COLOR": "red",
 *                  "ICON":"sap-icon://message-warning"
 *              }]     
 *          },
 *          "operator": "AND",
 *          "conditions": [{
 *              "expression": "[#ORDER_POS__PRODUCT__LIFECYCLE_STATE:MIN#]",
 *              "comparator": "<",
 *              "value": "50"
 *          }]
 *       }]
 *  }
 * 
 * ```
 * 
 * @author Andrej Kabachnik, Georg Bieger
 * 
 */
class ChecklistingBehavior extends AbstractValidatingBehavior
{    
    /**
     * @see AbstractBehavior::registerEventListeners()
     */
    protected function registerEventListeners() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->addListener(OnCreateDataEvent::getEventName(), $this->getEventHandlerToPerformChecks(), $this->getPriority());
        $this->getWorkbench()->eventManager()->addListener(OnUpdateDataEvent::getEventName(), $this->getEventHandlerToPerformChecks(), $this->getPriority());
        $this->getWorkbench()->eventManager()->addListener(OnBeforeUpdateDataEvent::getEventName(), $this->getEventHandlerToCacheOldData(), $this->getPriority());

        return $this;
    }

    /**
     * @see AbstractBehavior::unregisterEventListeners()
     */
    protected function unregisterEventListeners() : BehaviorInterface
    {
        $this->getWorkbench()->eventManager()->removeListener(OnCreateDataEvent::getEventName(), $this->getEventHandlerToPerformChecks());
        $this->getWorkbench()->eventManager()->removeListener(OnUpdateDataEvent::getEventName(), $this->getEventHandlerToPerformChecks());
        $this->getWorkbench()->eventManager()->removeListener(OnBeforeUpdateDataEvent::getEventName(), $this->getEventHandlerToCacheOldData());

        return $this;
    }


    protected function generateDataChecks(UxonObject $uxonObject): DataCheckListInterface
    {
        $dataCheckList = new BehaviorDataCheckList($this->getWorkbench(), $this);
        foreach ($uxonObject as $uxon) {
            $dataCheckList->add(new DataCheckWithOutputData($this->getWorkbench(), $uxon));
        }
        
        return $dataCheckList;
    }


    protected function processValidationResult(DataCheckFailedErrorMultiple $result, BehaviorLogBook $logbook): void
    {
        $outputSheets = [];
        $affectedUidAliases = [];
        
        foreach ($result->getAllErrors() as $error) {
            $check = $error->getCheck();
            if(!$check instanceof DataCheckWithOutputData) {
                continue;
            }

            if(!$checkOutputSheet = $check->getOutputDataSheet()) {
                continue;
            }
            
            $metaObjectAlias = $checkOutputSheet->getMetaObject()->getAlias();
            if(key_exists($metaObjectAlias,$outputSheets)) {
                $outputSheets[$metaObjectAlias]->addRows($checkOutputSheet->getRows());
            } else {
                // We need to maintain separate sheets for each MetaObjectAlias, in case the designer
                // configured data checks associated with different MetaObjects.
                $outputSheets[$metaObjectAlias] = $checkOutputSheet;
                $affectedUidAliases[$metaObjectAlias] = $check->getAffectedUidAlias();
            }
        }
        
        $logbook->addLine('Processing output data sheets...');
        $logbook->addIndent(1);
        foreach ($outputSheets as $metaObjectAlias => $outputSheet) {
            if($outputSheet === null || $outputSheet->countRows() === 0) {
                continue;
            }

            $logbook->addDataSheet('Output-'.$metaObjectAlias, $outputSheet);
            $logbook->addLine('Working on sheet for '.$metaObjectAlias.'...');
            $affectedUidAlias = $affectedUidAliases[$metaObjectAlias];
            $logbook->addLine('Affected UID-Alias is '.$affectedUidAlias.'.');
            // We filter by affected UID rather than by native UID to ensure that our delete operation finds all cached outputs,
            // especially if they were part of the source transaction.
            $outputSheet->getFilters()->addConditionFromValueArray($affectedUidAlias, $outputSheet->getColumnValues($affectedUidAlias));
            // We want to delete ALL entries for any given affected UID to ensure that the cache only contains outputs
            // that actually matched the current round of validations. This way we essentially clean up stale data.
            $deleteSheet = $outputSheet->copy();
            // Remove the UID column, because otherwise dataDelete() ignores filters and goes by UID.
            $deleteSheet->getColumns()->remove($deleteSheet->getUidColumn());
            $logbook->addLine('Deleting data with affected UIDs from cache.');
            $count = $deleteSheet->dataDelete();
            $logbook->addLine('Deleted '.$count.' lines from cache.');
            // Finally, write the most recent outputs to the cache.
            $logbook->addLine('Writing data to cache.');
            $count = $outputSheet->dataUpdate(true);
            $logbook->addLine('Added '.$count.' lines to cache.');
        }
        $logbook->addIndent(-1);
    }

    /**
     * Triggers only when data is being CREATED.
     * 
     *  ### Placeholders:
     * 
     *  - `[#~new:alias#]`: Loads the value the specified alias will hold AFTER the event has been applied.
     * 
     * @uxon-property check_on_create
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataCheckWithOutputData[]
     * @uxon-template [{"affected_uid_alias":"AFFECTED_UID", "output_data_sheet":{"object_alias": "", "rows": [{"CRITICALITY":"0", "LABELS":"", "MESSAGE":"", "COLOR":"", "ICON":"sap-icon://message-warning"}]}, "operator": "AND", "conditions": [{"expression": "", "comparator": "", "value": ""}]}]
     * 
     * @param UxonObject $uxon
     * @return AbstractValidatingBehavior
     */
    public function setCheckOnCreate(UxonObject $uxon) : AbstractValidatingBehavior
    {
        $this->setUxonForEventContext($uxon,self::CONTEXT_ON_CREATE);
        return $this;
    }

    /**
     * Triggers only when data is being UPDATED.
     * 
     * ### Placeholders:
     * 
     *  - `[#~old:alias#]`: Loads the value the specified alias held BEFORE the event was applied.
     *  - `[#~new:alias#]`: Loads the value the specified alias will hold AFTER the event has been applied.
     * 
     * @uxon-property check_on_update
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataCheckWithOutputData[]
     * @uxon-template [{"affected_uid_alias":"AFFECTED_UID", "output_data_sheet":{"object_alias": "", "rows": [{"CRITICALITY":"0", "LABELS":"", "MESSAGE":"", "COLOR":"", "ICON":"sap-icon://message-warning"}]}, "operator": "AND", "conditions": [{"expression": "", "comparator": "", "value": ""}]}]
     * 
     * 
     * @param UxonObject $uxon
     * @return AbstractValidatingBehavior
     */
    public function setCheckOnUpdate(UxonObject $uxon) : AbstractValidatingBehavior
    {
        $this->setUxonForEventContext($uxon,self::CONTEXT_ON_UPDATE);
        return $this;
    }

    /**
     * Triggers BOTH when data is being CREATED and UPDATED.
     * 
     * ### Placeholders:
     * 
     * - `[#~new:alias#]`: Loads the value the specified alias will hold AFTER the event has been applied.
     * 
     * @uxon-property check_always
     * @uxon-type \exface\Core\CommonLogic\DataSheets\DataCheckWithOutputData[]
     * @uxon-template [{"affected_uid_alias":"AFFECTED_UID", "output_data_sheet":{"object_alias": "", "rows": [{"CRITICALITY":"0", "LABELS":"", "MESSAGE":"", "COLOR":"", "ICON":"sap-icon://message-warning"}]}, "operator": "AND", "conditions": [{"expression": "", "comparator": "", "value": ""}]}]
     * 
     * @param UxonObject $uxon
     * @return AbstractValidatingBehavior
     */
    public function setCheckAlways(UxonObject $uxon) : AbstractValidatingBehavior
    {
        $this->setUxonForEventContext($uxon,self::CONTEXT_ON_ANY);
        return $this;
    }
}