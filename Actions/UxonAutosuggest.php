<?php
namespace exface\Core\Actions;

use exface\Core\CommonLogic\AbstractAction;
use exface\Core\Interfaces\Tasks\TaskInterface;
use exface\Core\Interfaces\DataSources\DataTransactionInterface;
use exface\Core\Interfaces\Tasks\ResultInterface;
use exface\Core\Factories\ResultFactory;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Uxon\UxonSchema;
use exface\Core\Uxon\WidgetSchema;
use exface\Core\Uxon\ActionSchema;
use exface\Core\Uxon\DatatypeSchema;
use exface\Core\Uxon\BehaviorSchema;
use exface\Core\DataTypes\StringDataType;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\DataTypes\SortingDirectionsDataType;

/**
 * Returns autosuggest values for provided UXON objects.
 * 
 * Accepts the following parameters:
 * 
 * - `uxon` - the entire UXON being edited
 * - `text` - the text, that needs to get suggested (i.e. what was typed so far)
 * - `path` - the path to the currently edited node from the root of the UXON (a JSON array of node names)
 * - `input` - the type of input: `field` or `value`
 * - `schema` - the UXON schema to be used (if not set, the generic UxonSchema will be used).
 * - `prototype` - class or file path (relative to the vendor folder) to be used as prototype
 * - `object` - a selector for the root object of the UXON
 * 
 * The result is a JSON with the following structure
 * 
 * {
 *  "values": [
 *      "suggestion 1",
 *      "suggestion 2",
 *      "..."
 *  ],
 *  "templates": {
 *      "field1": {"": ""},
 *      "field2": ["": ""]
 *  }
 * }
 * 
 * @author Andrej Kabachnik
 *
 */
class UxonAutosuggest extends AbstractAction
{
    const SCHEMA_WIDGET = 'widget';
    const SCHEMA_ACTION = 'action';
    const SCHEMA_BEHAVIOR = 'behavior';
    const SCHEMA_DATATYPE = 'datatype';
    
    const PARAM_TEXT = 'text';
    const PARAM_PATH = 'path';
    const PARAM_TYPE = 'input';
    const PARAM_UXON = 'uxon';
    const PARAM_SCHEMA = 'schema';
    const PARAM_OBJECT = 'object';
    const PARAM_PROTOTYPE = 'prototype';
    
    const TYPE_FIELD = 'field';
    const TYPE_VALUE = 'value';
    const TYPE_PRESET = 'preset';
    const TYPE_DETAILS = 'details';
    
    protected function perform(TaskInterface $task, DataTransactionInterface $transaction) : ResultInterface
    {
        $options = [];
        
        $currentText = $task->getParameter(self::PARAM_TEXT);
        $path = json_decode($task->getParameter(self::PARAM_PATH), true);
        $type = $task->getParameter(self::PARAM_TYPE);
        $uxon = UxonObject::fromJson($task->getParameter(self::PARAM_UXON));
        $schema = $task->getParameter(self::PARAM_SCHEMA);
        
        if ($rootObjectSelector = $task->getParameter(self::PARAM_OBJECT)) {
            try {
                $rootObject = $this->getWorkbench()->model()->getObject($rootObjectSelector);
            } catch (MetaObjectNotFoundError $e) {
                $rootObject = null;  
            }
        }
        
        if ($rootPrototypeSelector = trim($task->getParameter(self::PARAM_PROTOTYPE))) {
            if (StringDataType::endsWith($rootPrototypeSelector, '.php', false)) {
                $rootPrototypeClass = str_replace("/", "\\", substr($rootPrototypeSelector, 0, -4));
                $rootPrototypeClass = "\\" . ltrim($rootPrototypeClass, "\\");
            } elseif (! $rootPrototypeSelector || $rootObjectSelector === 'null' || $rootObjectSelector === 'undefined') {
                $rootPrototypeClass = null;
            } else {
                $rootPrototypeClass = $rootPrototypeSelector;
            }
        }
        
        switch (mb_strtolower($schema)) {
            case self::SCHEMA_WIDGET: 
                $schema = new WidgetSchema($this->getWorkbench());
                break;
            case self::SCHEMA_ACTION:
                $schema = new ActionSchema($this->getWorkbench());
                break;
            case self::SCHEMA_DATATYPE:
                $schema = new DatatypeSchema($this->getWorkbench());
                break;
            case self::SCHEMA_BEHAVIOR:
                $schema = new BehaviorSchema($this->getWorkbench());
                break;
        }
        
        switch (true) {
            case strcasecmp($type, self::TYPE_FIELD) === 0:
                $options = $this->suggestPropertyNames($schema, $uxon, $path, $rootPrototypeClass);
                break;
            case strcasecmp($type, self::TYPE_PRESET) === 0:
                $options = $this->suggestPresets($schema, $uxon, $path, $rootPrototypeClass);
                break;
            case strcasecmp($type, self::TYPE_DETAILS) === 0:
                $options = $this->suggestDetails($schema, $uxon, $path, $rootPrototypeClass);
                break;
            default:
                $options = $this->suggestPropertyValues($schema, $uxon, $path, $currentText, $rootPrototypeClass, $rootObject);
                break;
        }
        return ResultFactory::createJSONResult($task, $options);
    }

    /**
     * 
     * @param UxonSchema $schema
     * @param UxonObject $uxon
     * @param array $path
     * @param string $rootPrototypeClass
     * @return array
     */
    protected function suggestPresets(UxonSchema $schema, UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $presets = [];
        
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.' . strtoupper($schema::getSchemaName()) . '_PRESET');
        $ds->getColumns()->addMultiple(['UID','NAME', 'PROTOTYPE__LABEL', 'DESCRIPTION', 'PROTOTYPE', 'UXON' , 'WRAP_PATH', 'WRAP_FLAG']);
        $ds->addFilterFromString('UXON_SCHEMA', $schema::getSchemaName());
        $ds->getSorters()
            ->addFromString('PROTOTYPE', SortingDirectionsDataType::ASC)
            ->addFromString('NAME', SortingDirectionsDataType::ASC);
        $ds->dataRead();
        
        $class = $schema->getPrototypeClass($uxon, $path, $rootPrototypeClass);
        
        foreach ($ds->getRows() as $row) {
            // TODO: Leerer Editor, oberste Knoten => class ist abstract widget => keine Filterung 
            // Class Plus Wrapper-Presets (ausser AbstractWidget) 
            $presets[] = $row;
        }
        
        return $presets;
    }
    
    /**
     * 
     * @param UxonSchema $schema
     * @param UxonObject $uxon
     * @param array $path
     * @param string $rootPrototypeClass
     * @return array
     */
    protected function suggestDetails(UxonSchema $schema, UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $rows = [];
        if (empty($path) === false) {
            $path[] = '';
        }
        $prototypeClass = $schema->getPrototypeClass($uxon, $path, $rootPrototypeClass);
        $prototypeSchemaClass = $prototypeClass::getUxonSchemaClass();
        $filepathRelative = $schema->getFilenameForEntity($prototypeClass);
        
        $ds = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), 'exface.Core.UXON_PROPERTY_ANNOTATION');
        $ds->getColumns()->addMultiple([
            'PROPERTY', 
            'TYPE', 
            'TEMPLATE', 
            'DEFAULT', 
            'TITLE', 
            'REQUIRED'
            
        ]);
        $ds->addFilterFromString('FILE', $filepathRelative);
        $ds->getSorters()->addFromString('PROPERTY', SortingDirectionsDataType::ASC);
        
        try {
            $ds->dataRead();
        } catch (\Throwable $e) {
            // TODO
        }
        $rows = $ds->getRows();
        
        // TODO transform enum-types to arrays
        
        return [
            'alias' => StringDataType::substringAfter($prototypeClass, '\\', '', false, true),
            'prototype' => $prototypeClass,
            'prototype_schema' => $prototypeSchemaClass::getSchemaName(),
            'properties' => $rows
        ];
    }
    
    /**
     * 
     * @param UxonSchema $schema
     * @param UxonObject $uxon
     * @param array $path
     * @param string $rootPrototypeClass
     * @return array
     */
    protected function suggestPropertyNames(UxonSchema $schema, UxonObject $uxon, array $path, string $rootPrototypeClass = null) : array
    {
        $prototypeClass = $schema->getPrototypeClass($uxon, $path, $rootPrototypeClass);
        return [
            'values' => $schema->getProperties($prototypeClass),
            'templates' => $schema->getPropertiesTemplates($prototypeClass)
        ];
    }
    
    protected function suggestPropertyValues(UxonSchema $schema, UxonObject $uxon, array $path, string $valueText, string $rootPrototypeClass = null, MetaObjectInterface $rootObject = null) : array
    {
        return ['values' => $schema->getValidValues($uxon, $path, $valueText, $rootPrototypeClass, $rootObject)];
    }
}