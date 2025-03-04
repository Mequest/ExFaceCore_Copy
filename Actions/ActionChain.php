<?php
namespace exface\Core\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\Actions\ActionInterface;
use exface\Core\Factories\ActionFactory;
use exface\Core\Exceptions\Actions\ActionConfigurationError;
use exface\Core\Interfaces\ActionListInterface;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Exceptions\Widgets\WidgetPropertyInvalidValueError;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\CommonLogic\Tasks\ResultData;
use exface\Core\Interfaces\Actions\iCallOtherActions;
use exface\Core\CommonLogic\Tasks\ResultEmpty;
use exface\Core\Factories\ResultFactory;
use exface\Core\Actions\Traits\iCallOtherActionsTrait;
use exface\Core\Interfaces\Tasks\ResultDataInterface;
use exface\Core\Interfaces\Actions\iShowDialog;
use exface\Core\CommonLogic\Debugger\LogBooks\DataLogBook;

/**
 * This action chains other actions and performs them one after another.
 *
 * All actions in the `action` array will be performed one-after-another in the order of definition. 
 * Every action receives the result data of it's predecessor as input. The first action will get 
 * the input from the chain action. The result of the action chain is the result of the last action
 * unless `use_result_of_action` is set explicitly.
 * 
 * **NOTE:** actions showing widgets may cause problems or not work at all if placed in the middle of
 * a chain - this will depend on the facade used!
 * 
 * The action chain will inherit many properties from its first action: 
 * - name 
 * - icon 
 * - trigger widget 
 * - expected number of input rows
 * 
 * ## Passing values from one action to another
 * 
 * Each action will receive the output of the previous one as input data. In many cases, you might need
 * other data too - e.g. user input meant for a specific action somewhere in the chain. In this case,
 * you can place the required values in context variables using `column_to_variable_mappings` in the 
 * `input_mapper` of the first action or of the chain itself. To read the variables simply use 
 * `variable_to_column_mappings` in the `input_mapper` of the action requiring the data or the formula 
 * `=GetContextVar()` in any mapper along the chain. 
 * 
 * ## Action effects
 * 
 * The chain has modified data if at least one of the actions modified data.
 * 
 * The chain will have `effects` on all objects effected by its sub-actions. However, if multiple 
 * actions effect the same object, only the first effect will be inherited by the chain. In particular,
 * this makes sure manually defined `effects` of the chain itself prevail!
 * 
 * ## Transaction handling
 * 
 * By default, all actions in the chain will be performed in a single transaction. That is, all actions 
 * will get rolled back if at least one failes. Set the property `use_single_transaction` to false 
 * to make every action in the chain run in it's own transaction.
 * 
 * ## Nested chains
 *
 * Action chains can be nested - an action in the chain can be another chain. This way, complex processes 
 * even with multiple transactions can be modeled (e.g. the root chain could have `use_single_transaction` 
 * disabled, while nested chains would each have a wrapping transaction).
 * 
 * ## Examples
 * 
 * ### Simple chain
 *
 * Here is a simple example for releasing an order and priniting it with a single button:
 * 
 * ```
 *  {
 *      "alias": "exface.Core.ActionChain",
 *      "name": "Release",
 *      "input_rows_min": 1,
 *      "input_rows_max": 1,
 *      "actions": [
 *          {
 *              "alias": "my.app.ReleaseOrder",
 *          },
 *          {
 *              "alias": "my.app.PrintOrder"
 *          }
 *      ]
 *  }
 * 
 * ```
 * 
 * ### Primary action with additional operations
 * 
 * In this example, the first action is what the user actually intends to do. However, this implies, 
 * that other actions need to be done (in this case, changing the `STATUS` attribute to `95`).
 * 
 * ```
 *  {
 *      "alias": "exface.Core.ActionChain",
 *      "name": "Retry",
 *      "input_rows_min": 1,
 *      "use_result_of_action": 0,
 *      "use_input_data_of_action": 0,
 *      "actions": [
 *          {
 *              "alias": "exface.Core.CopyData",
 *          },
 *          {
 *              "alias": "exface.Core.UpdateData",
 *              "inherit_columns": "own_system_attributes",
 *              "column_to_column_mappings": [
 *                  {
 *                      "from": 95,
 *                      "to": "STATUS"
 *                  }
 *              ]
 *          }
 *      ]
 *  }
 * 
 * ```
 * 
 * ### Complex nested chain
 * 
 * In this example, the first aciton of the chain is another (inner) chain:
 * 
 * 1. The first action of the inner chain copies some object
 * 2. The second action of the inner chain sets the status of the original
 * object (it modifies the original and not the copied object because
 * `use_input_data_of_action` of the chain is set to `0`, so all actions
 * get the same input data as the action with index 0)
 * 3. The inner chain passes the result of it's first action - the copied
 * object - to the second action of the outer chain. The result of the inner
 * chain is controlled by `use_result_of_action` forcing the chain to yield
 * the result of the action with index 0.
 * 4. The second action of the outer chain does further processing of the
 * copied object.
 * 
 * All of this is happening in a single transaction (as long as the data source
 * supports transactions, of course). Thus, if anything goes wrong in any
 * of the chained actions, everything will get rolled back.
 * 
 * ```
 * {
 *   "alias": "exface.Core.ActionChain",
 *   "actions": [
 *     {
 *       "alias": "exface.Core.ActionChain",
 *       "use_result_of_action": 0,
 *       "use_input_data_of_action": 0,
 *       "actions": [
 *         {
 *           "alias": "exface.Core.CopyData"
 *         },
 *         {
 *           "alias": "exface.Core.UpdateData",
 *           "input_mapper": {
 *             "inherit_columns": "own_system_attributes",
 *             "column_to_column_mappings": [
 *               {
 *                 "from": 95,
 *                 "to": "STATUS"
 *               }
 *             ]
 *           }
 *         }
 *       ]
 *     },
 *     {
 *       "alias": "my.App.FurtherProcessing"
 *     }
 *   ]
 * }
 * 
 * ```
 * 
 * @author Andrej Kabachnik
 *        
 */
class ActionChain extends AbstractAction implements iCallOtherActions
{
    use iCallOtherActionsTrait;
    
    private $actions = [];

    private $use_single_transaction = true;
    
    private $use_result_from_action_index = null;
    
    private $freeze_input_data_at_action_index = null;
    
    private $skip_action_if_empty_input = false;
    
    private $result_message_delimiter = "\n";

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::perform()
     */
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        if (empty($this->getActions())) {
            throw new ActionConfigurationError($this, 'An action chain must contain at least one action!', '6U5TRGK');
        }
        
        $inputSheet = $this->getInputDataSheet($task);
        $freezeInputIdx = $this->getUseInputDataOfAction();
        $triggerWidget = $this->isDefinedInWidget() ? $this->getWidgetDefinedIn() : null;
        $chainDataModified = false;
        $chainMessage = null;
        $chainResult = null;
        $messages = [];
        $results = [];
        $t = $task;
        $logbook = $this->getLogBook($task);
        $logbook->addLine('### Important action properties:');
        $logbook->addLine("`skip_action_if_input_empty`: `" . ($this->getSkipActionsIfInputEmpty() ? 'true' : 'false') . "`", +1);
        $logbook->addLine("`use_single_transaction`: `" . ($this->getUseSingleTransaction() ? 'true' : 'false') . "`", +1);
        $logbook->addLine("`use_input_data_of_action`: `" . ($this->getUseInputDataOfAction() ?? ' ') . "`", +1);
        $logbook->addLine("`use_result_of_action`: `" . ($this->getUseResultOfAction() ?? ' ') . "`", +1);
        $logbook->addLine('### Workflow');
        
        $actions = $this->getActions();
        $idxLast = count($actions) - 1;
        
        // Prepare the flow diagram in mermaid.js syntax
        // Place the nodes from left to right, if there are max. 3 nodes and top-down if there are more
        $lbId = $logbook->getId();
        $diagram .= 'graph ' . (count($this->getActions()) > 3 ? 'TD' : 'LR');
        $diagram .= PHP_EOL . "{$lbId}T(Task)";
        foreach ($actions as $idx => $action) {
            $diagram .= PHP_EOL . "{$lbId}{$idx}[{$action->getAliasWithNamespace()}]";
        }
        
        foreach ($actions as $idx => $action) {
            $diagramShapeId = "{$lbId}{$idx}";
            if ($idx === 0) {
                $diagram .= PHP_EOL . "{$lbId}T";
            }
            // Skip show-dialog actions. It does not make sense to really perform them within the chain.
            // Instead, they are currently called separately by the front-end similar to front-end-actions
            // - see `JQueryButtonTrait::buildJsClickActionChain()` for details
            if ($action instanceof iShowDialog) {
                $diagram .= " .-> {$diagramShapeId}";
                continue;
            }
            
            // Prepare the action
            // All actions are all called by the widget, that called the chain
            if ($triggerWidget !== null) {
                $action->setWidgetDefinedIn($triggerWidget);
            }
            
            // If the chain should run in a single transaction, this transaction must 
            // be set for every action to run in. Autocommit must be disabled for
            // every action too!
            if ($this->getUseSingleTransaction()) {
                $tx = $transaction;
                $action->setAutocommit(false);
            } else {
                $tx = $this->getWorkbench()->data()->startTransaction();
            }
            
            // Let the action handle a copy of the task
            // Every action gets the data resulting from the previous action as input data
            $t = $t->copy()->setInputData($inputSheet);
            
            // Perform the action if it should not be skipped
            $skip = ($this->getSkipActionsIfInputEmpty() === true && $inputSheet->isEmpty() && $action->getInputRowsMin() !== 0);
            $logbook->addLine("[{$idx}] **" . ($skip ? 'Skip' : 'Do') . "** {$action->getAliasWithNamespace()} on {$inputSheet->countRows()} rows of {$inputSheet->getMetaObject()->__toString()} (`input_rows_min: {$action->getInputRowsMin()}`)");
            if ($skip === true) {
                // mermaid: ... .-x 1[alias]
                $diagram .= " .-x {$diagramShapeId}";
            } else {
                // mermaid: ... -->|...| id1[alias]
                $diagram .= " -->|" . DataLogBook::buildMermaidTitleForData($inputSheet) . "| $diagramShapeId";
                try {
                    $lastResult = $action->handle($t, $tx);
                } catch (\Throwable $e) {
                    if ($idx === 0) {
                        $diagram .= PHP_EOL . "$diagramShapeId --> {$lbId}ERR(Error)";
                        $diagram .= PHP_EOL . "style {$lbId}ERR {$logbook->getFlowDiagramStyleError()}";
                    }
                    $diagram .= PHP_EOL . "style {$diagramShapeId} {$logbook->getFlowDiagramStyleError()}";
                    $logbook->setFlowDiagram($diagram);
                    throw $e;
                }
                $results[$idx] = $lastResult;
                if (null !== $lastMessage = $lastResult->getMessage()) {
                    $messages[$idx] = $lastMessage;
                }
                if ($lastResult->isDataModified()) {
                    $chainDataModified = true;
                }
                // Determine the input data for the next action: either take that of the last data result or
                // the explicitly specified step id in `user_result_of_action`
                // mermaid: preparation for the next --> ...
                if ($freezeInputIdx === null || $freezeInputIdx > $idx) {
                    if ($lastResult instanceof ResultData) {
                        $inputSheet = $lastResult->getData();
                    }
                    $diagram .= $idx < $idxLast ? PHP_EOL . $diagramShapeId : '';
                } else {
                    // If the input is always taken from a certain step, the arrow needs to start from the
                    // step before it!
                    $diagram .= $idx < $idxLast ? PHP_EOL . $lbId . ($freezeInputIdx > 0 ? ($freezeInputIdx-1) : 'T') : '';
                }
            }
        }
        
        if (null !== $resultIdx = $this->getUseResultOfAction()) {
            $chainResult = $results[$resultIdx];
        } else {
            $chainResult = $lastResult;
            $resultIdx = $idx;
        }
        $chainMessage = $this->getResultMessageText() ?? trim(implode($this->getResultMessageDelimiter(), $messages));
        switch (true) {
            case $chainResult === null:
                $chainResult = ResultFactory::createEmptyResult($task);
                break;
            case $chainMessage !== null && $chainMessage !== '': 
                if ($chainResult instanceof ResultEmpty) {
                    $chainResult = ResultFactory::createMessageResult($task, $chainMessage);
                } else {
                    $chainResult = $chainResult->withTask($task)->setMessage($chainMessage);
                }
                break;
        }
        
        $chainResultArrowComment = ($chainResult instanceof ResultDataInterface) ? "|" . DataLogBook::buildMermaidTitleForData($chainResult->getData()) . "|" : '';
        $diagram .= PHP_EOL . "{$lbId}{$resultIdx} -->{$chainResultArrowComment} {$lbId}R(Result)" . PHP_EOL;
        $logbook->setIndentActive(0);
        $logbook->setFlowDiagram($diagram);
        
        $chainResult->setDataModified($chainDataModified);
        
        return $chainResult;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallOtherActions::getActions()
     */
    public function getActions() : array
    {
        return $this->actions;
    }
    
    /**
     * 
     * @param ActionInterface $action
     * @return int
     */
    public function getActionIndex(ActionInterface $action) : int
    {
        return array_search($action, $this->getActions(), true);
    }
    
    /**
     * Attempts to find the action to start the chain from - for a given task.
     * 
     * - If the task references the entire chain, the first action in the chain is returned
     * - If the task matches exactly one action in the chain, that action is returned
     * - otherwise NULL is returned
     * 
     * Background: complex chains with front-end and back-end actions may require multiple
     * server requests for a single chain with different tasks. It is not really clear,
     * how to match the chain steps reliably. The blelow code works for the cases we know
     * so far:
     * 
     * - Task for the entire action
     * - Task for a ReadPrefill action resulting from a ShowWidget action somewhere along 
     * the chain (the method would return NULL, which is the same behavior as for regular 
     * ReadPrefill actions without chains - see `GenericTask::getAction()`)
     * - Task with an actions selector that matches one or more actoin along the chain.
     * There was no usecase for this so far.
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallOtherActions::getActionToStart()
     */
    public function getActionToStart(TaskInterface $task) : ?ActionInterface
    {
        $taskActionSelector = $task->getActionSelector();
        if ($this->isExactly($taskActionSelector)) {
            return $this->getActionFirst();
        }
        $found = [];
        foreach ($this->getActions() as $action) {
            if ($action->is($taskActionSelector)) {
                $found[] = $action;
                break;
            }
        }
        if (count($found) === 1) {
            return reset($found);
        }
        return null;
    }
    
    /**
     * 
     * @return ActionInterface
     */
    public function getActionFirst() : ActionInterface
    {
        return $this->getActions()[0];
    }

    /**
     * Array of action UXON descriptions for every action in the chain.
     * 
     * @uxon-property actions
     * @uxon-type \exface\Core\CommonLogic\AbstractAction[]
     * @uxon-template [{"alias": ""}]
     * 
     * @param UxonObject|ActionInterface[] $uxon_array_or_action_list
     * 
     * @throws ActionConfigurationError
     * @throws WidgetPropertyInvalidValueError
     * 
     * @return iCallOtherActions
     */
    public function setActions($uxon_array_or_action_list) : iCallOtherActions
    {
        if ($uxon_array_or_action_list instanceof ActionListInterface) {
            $this->actions = $uxon_array_or_action_list;
        } elseif ($uxon_array_or_action_list instanceof UxonObject) {
            foreach ($uxon_array_or_action_list as $nr => $uxon) {
                if ($uxon instanceof UxonObject) {
                    // Make child-actions inherit the trigger widget
                    $triggerWidget = $this->isDefinedInWidget() ? $this->getWidgetDefinedIn() : null;
                    // If there is not trigger widget, make them inherit the meta object by
                    // explicitly specifying it in the UXON. This "workaround" is neccessary
                    // because the child-actions do not actually know, that they are part of
                    // a chain and would not have a fall-back when looking for their object.
                    if ($triggerWidget === null && ! $uxon->hasProperty('object_alias')) {
                        $uxon->setProperty('object_alias', $this->getMetaObject()->getAliasWithNamespace());
                    }
                    $action = ActionFactory::createFromUxon($this->getWorkbench(), $uxon, $triggerWidget);
                } elseif ($uxon instanceof ActionInterface) {
                    $action = $uxon;
                } else {
                    throw new ActionConfigurationError($this, 'Invalid chain link of type "' . gettype($uxon) . '" in action chain on position ' . $nr . ': only actions or corresponding UXON objects can be used as!', '6U5TRGK');
                }
                $this->addAction($action);
            }
        } else {
            throw new WidgetPropertyInvalidValueError('Cannot set actions for ' . $this->getAliasWithNamespace() . ': invalid format ' . gettype($uxon_array_or_action_list) . ' given instead of and instantiated condition or its UXON description.');
        }
        
        return $this;
    }

    /**
     * 
     * @param ActionInterface $action
     * @throws ActionConfigurationError
     * @return iCallOtherActions
     */
    protected function addAction(ActionInterface $action) : iCallOtherActions
    {
        $this->actions[] = $action;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Actions\iCallOtherActions::getUseSingleTransaction()
     */
    public function getUseSingleTransaction() : bool
    {
        if ($this->getAutocommit() === false) {
            return true;
        }
        return $this->use_single_transaction;
    }

    /**
     * Set to FALSE to enclose each action in a separate transaction.
     * 
     * By default the entire chain is enclosed in a transaction, so no changes
     * are made to the data sources if at least one action fails.
     * 
     * **NOTE:** not all data sources support transactions! Non-transactional
     * sources will keep eventual changes even if the action chain is rolled
     * back!
     * 
     * @uxon-property use_single_transaction
     * @uxon-type boolean
     * @uxon-default true
     * 
     * @param bool $value
     * @return iCallOtherActions
     */
    public function setUseSingleTransaction(bool $value) : iCallOtherActions
    {
        $this->use_single_transaction = $value;
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getInputRowsMin()
     */
    public function getInputRowsMin()
    {
        return max(parent::getInputRowsMin(), $this->getActionFirst()->getInputRowsMin());
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getInputRowsMax()
     */
    public function getInputRowsMax()
    {
        return parent::getInputRowsMax() ?? $this->getActionFirst()->getInputRowsMax();
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::isUndoable()
     */
    public function isUndoable() : bool
    {
        return false;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getName()
     */
    public function getName()
    {
        if (! parent::hasName() && empty($this->getActions()) === false) {
            return $this->getActionFirst()->getName();
        }
        return parent::getName();
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getIcon()
     */
    public function getIcon() : ?string
    {
        return parent::getIcon() ? parent::getIcon() : $this->getActionFirst()->getIcon();
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::implementsInterface()
     */
    public function implementsInterface($interface)
    {
        if (empty($this->getActions())){
            return parent::implementsInterface($interface);
        }
        return $this->getActionFirst()->implementsInterface($interface);
    }
    
    /**
     * For every method not exlicitly inherited from AbstractAciton attemt to call it on the first action.
     * 
     * @param mixed $method
     * @param mixed $arguments
     * @return mixed
     */
    public function __call($method, $arguments){
        return call_user_func_array(array($this->getActionFirst(), $method), $arguments);
    }
    
    /**
     * 
     * @return int|NULL
     */
    public function getUseResultOfAction() : ?int
    {
        return $this->use_result_from_action_index;
    }
    
    /**
     * Index of the inner action (starting with 0), that should provide the result of the chain.
     * 
     * By default, the result of the chain is the result of the last action in the
     * chain. Using this property, you can make the chain yield the result of any
     * other action. 
     * 
     * Use `0` for the first action in the chain, `1` for the second, etc. All subsequent 
     * actions will still get performed normally, but the result of this specified action
     * will be returned by the chain at the end.
     * 
     * This option can be used in combination with `use_input_data_of_action` to add
     * "secondary" actions, that perform additional operations on the same data as the
     * main action and do not affect the result.
     * 
     * @uxon-property use_result_of_action
     * @uxon-type integer
     * @uxon-template 0
     * 
     * @param int $value
     * @return ActionChain
     */
    public function setUseResultOfAction(int $value) : ActionChain
    {
        $this->use_result_from_action_index = $value;
        return $this;
    }
    
    /**
     * 
     * @return int|NULL
     */
    public function getUseInputDataOfAction() : ?int
    {
        return $this->freeze_input_data_at_action_index;
    }
    
    /**
     * Make all actions after the given index (starting with 0) use the same input data.
     * 
     * By default, each action uses the result data of the previous action as it's input.
     * Setting this option will force all actions after the given index to use the same
     * input data as the action at that index: e.g. 
     * - `0` means all actions will use the input data of the first action, 
     * - `1` means the second action will use the result of the first one as input, but
     * all following actions will use the same data as input.
     * 
     * This option can be used in combination with `use_result_of_action` to add
     * "secondary" actions, that perform additional operations on the same data as the
     * main action and do not affect the result.
     * 
     * @uxon-property use_input_data_of_action
     * @uxon-type integer
     * @uxon-template 0
     * 
     * @param int $value
     * @return ActionChain
     */
    public function setUseInputDataOfAction(int $value) : ActionChain
    {
        $this->freeze_input_data_at_action_index = $value;
        return $this;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\AbstractAction::getEffects()
     */
    public function getEffects() : array
    {
        $effects = parent::getEffects();
        foreach ($this->getActions() as $action) {
            foreach ($action->getEffects() as $effect) {
                foreach ($effects as $chainEffect) {
                    if ($chainEffect->getEffectedObject()->isExactly($effect->getEffectedObject)) {
                        continue 2;
                    }
                }
                $effects[] = $effect;
            }
        }
        return $effects;
    }
    
    /**
     *
     * {@inheritdoc}
     * @see iCanBeConvertedToUxon::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = parent::exportUxonObject();
        foreach ($this->getActions() as $action) {
            $uxon->appendToProperty('actions', $action->exportUxonObject());
        }
        return $uxon;
    }
    
    public function getSkipActionsIfInputEmpty() : bool
    {
        return $this->skip_action_if_empty_input;
    }
    
    /**
     * Skip any action if it requires input, but there is none - instead of throwing an error.
     * 
     * @uxon-property skip_actions_if_input_empty
     * @uxon-type boolean
     * @uxon-default false
     * 
     * @param bool $value
     * @return ActionChain
     */
    public function setSkipActionsIfInputEmpty(bool $value) : ActionChain
    {
        $this->skip_action_if_empty_input = $value;
        return $this;
    }
    
    /**
     * 
     * @return string
     */
    protected function getResultMessageDelimiter() : string
    {
        return $this->result_message_delimiter;
    }
    
    /**
     * Specifies how to concatenate result messages of all the actions
     * 
     * @uxon-property result_message_delimiter
     * @uxon-type string
     * @uxon-default \n
     * 
     * @param string $value
     * @return ActionChain
     */
    public function setResultMessageDelimiter(string $value) : ActionChain
    {
        $this->result_message_delimiter = $value;
        return $this;
    }
}