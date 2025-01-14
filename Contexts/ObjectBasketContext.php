<?php
namespace exface\Core\Contexts;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\Model\MetaObjectInterface;
use exface\Core\Exceptions\Contexts\ContextOutOfBoundsError;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\Factories\DataSheetFactory;
use exface\Core\Exceptions\Contexts\ContextRuntimeError;
use exface\Core\CommonLogic\Contexts\AbstractContext;
use exface\Core\Widgets\Container;
use exface\Core\Factories\WidgetFactory;
use exface\Core\CommonLogic\Constants\Icons;
use exface\Core\Exceptions\Model\MetaObjectNotFoundError;

/**
 * The ObjectBasketContext provides a unified interface to store links to selected instances of meta objects in any context scope.
 * If used in the WindowScope it can represent "pinned" objects, while in the UserScope it can be used to create favorites for this
 * user.
 *
 * Technically it stores a data sheet with instances for each object in the basket. Regardless of the input, this sheet will always
 * contain the default display columns.
 *
 * @author Andrej Kabachnik
 *        
 */
class ObjectBasketContext extends AbstractContext
{

    private $favorites = array();

    public function add(DataSheetInterface $data_sheet)
    {
        if (! $data_sheet->getUidColumn()) {
            throw new ContextRuntimeError($this, 'Cannot add object "' . $data_sheet->getMetaObject()->getAliasWithNamespace() . '" to object basket: missing UID-column "' . $data_sheet->getMetaObject()->getUidAttributeAlias() . '"!', '6TMQR5N');
        }
        
        $basket_data = $this->createBasketSheet($data_sheet->getMetaObject());
        $basket_data->importRows($data_sheet);
        if (! $basket_data->isFresh()) {
            $basket_data->getFilters()->addConditionFromValueArray($data_sheet->getUidColumn()->getName(), $data_sheet->getUidColumn()->getValues(false));
            $basket_data->dataRead();
        }
        
        $this->getBasketByObjectId($data_sheet->getMetaObject()->getId())->addRows($basket_data->getRows(), true);
        return $this;
    }

    protected function createBasketSheet(MetaObjectInterface $object)
    {
        $ds = DataSheetFactory::createFromObject($object);
        foreach ($object->getAttributes()->getDefaultDisplayList() as $attr) {
            $ds->getColumns()->addFromAttribute($attr);
        }
        return $ds;
    }

    protected function getObjectFromInput($meta_object_or_alias_or_id)
    {
        if ($meta_object_or_alias_or_id instanceof MetaObjectInterface) {
            $object = $meta_object_or_alias_or_id;
        } else {
            $object = $this->getWorkbench()->model()->getObject($meta_object_or_alias_or_id);
        }
        return $object;
    }

    /**
     *
     * @return DataSheetInterface[]
     */
    protected function getBaskets()
    {
        foreach ($this->favorites as $object_id => $data){
            if (! ($data instanceof DataSheetInterface)){
                $object_id = mb_strtolower($object_id);
                try {
                    $objectSheet = DataSheetFactory::createFromUxon($this->getWorkbench(), $data);
                    $this->favorites[$object_id] = $objectSheet;
                } catch (MetaObjectNotFoundError $e) {
                    $this->getWorkbench()->getLogger()->error(new ContextRuntimeError($this, 'Cannot find object saved in context: ' . $e->getMessage(), null, $e));
                    unset($this->favorites[$object_id]);
                }
            }
        }
        return $this->favorites;
    }

    /**
     *
     * @param string $object_id            
     * @return DataSheetInterface
     */
    public function getBasketByObjectId($object_id)
    {
        $object_id = strtolower($object_id);
        if (! $this->favorites[$object_id]) {
            $this->favorites[$object_id] = DataSheetFactory::createFromObjectIdOrAlias($this->getWorkbench(), $object_id);
        } elseif (($this->favorites[$object_id] instanceof UxonObject) || is_array($this->favorites[$object_id])) {
            $this->favorites[$object_id] = DataSheetFactory::createFromAnything($this->getWorkbench(), $this->favorites[$object_id]);
        }
        return $this->favorites[$object_id];
    }

    /**
     *
     * @param MetaObjectInterface $object            
     * @return DataSheetInterface
     */
    public function getBasketByObject(MetaObjectInterface $object)
    {
        return $this->getBasketByObjectId($object->getId());
    }

    /**
     *
     * @param string $alias_with_namespace            
     * @throws ContextOutOfBoundsError
     * @return DataSheetInterface
     */
    public function getBasketByObjectAlias($alias_with_namespace)
    {
        $object = $this->getWorkbench()->model()->getObjectByAlias($alias_with_namespace);
        if ($object) {
            return $this->getBasketByObjectId($object->getId());
        } else {
            throw new ContextOutOfBoundsError($this, 'ObjectBasket requested for non-existant object alias "' . $alias_with_namespace . '"!', '6T5E5VY');
        }
    }

    /**
     * The object basket context resides in the window scope by default.
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Contexts\AbstractContext::getDefaultScope()
     */
    public function getDefaultScope()
    {
        return $this->getWorkbench()->getContext()->getScopeSession();
    }

    /**
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\Contexts\AbstractContext::importUxonObject()
     */
    public function importUxonObject(UxonObject $uxon)
    {
        foreach ($uxon as $object_id => $data_uxon) {
            $this->favorites[strtolower($object_id)] = $data_uxon;
        }
    }

    /**
     * The favorites context is exported to the following UXON structure:
     *  {
     *      object_id1: {
     *          uid1: { data sheet },
     *          uid2: { data sheet },
     *          ...
     *      }
     *      object_id2: ...
     *  }
     *
     * {@inheritdoc}
     * @see \exface\Core\CommonLogic\Contexts\AbstractContext::exportUxonObject()
     */
    public function exportUxonObject()
    {
        $uxon = new UxonObject();
        foreach ($this->favorites as $object_id => $data_sheet) {
            if ($data_sheet instanceof DataSheetInterface){
                if (! $data_sheet->isEmpty()) {
                    $uxon->setProperty($object_id, $data_sheet->exportUxonObject());
                }
            } else {
                $uxon->setProperty($object_id, $data_sheet);
            }
        }
        return $uxon;
    }

    /**
     *
     * @param string $object_id            
     * @return \exface\Core\Contexts\ObjectBasketContext
     */
    public function removeInstancesForObjectId($object_id)
    {
        $object_id = strtolower($object_id);
        unset($this->favorites[$object_id]);
        return $this;
    }

    /**
     *
     * @param string $object_id            
     * @param string $uid            
     * @return \exface\Core\Contexts\ObjectBasketContext
     */
    public function removeInstance($object_id, $uid)
    {
        $this->getBasketByObjectId($object_id)->removeRowsByUid($uid);
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Contexts\AbstractContext::getIndicator()
     */
    public function getIndicator()
    {
        $i = 0;
        foreach ($this->favorites as $data_sheet) {
            if ($data_sheet instanceof DataSheetInterface){
                $i += $data_sheet->countRows();
            } elseif ($data_sheet instanceof UxonObject) {
                $i += $data_sheet->hasProperty('rows') ? $data_sheet->getProperty('rows')->countProperties() : 0;
            }
        }
        return $i;
    }

    public function getIcon() : ?string
    {
        return Icons::SHOPPING_BASKET;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\CommonLogic\Contexts\AbstractContext::getContextBarPopup()
     */
    public function getContextBarPopup(Container $container)
    {       
        /* @var $menu \exface\Core\Widgets\Menu */
        $menu = WidgetFactory::create($container->getPage(), 'Menu', $container);
        $menu->setCaption($this->getName());
        
        // Fill with buttons
        foreach ($this->getBaskets() as $data_sheet) {
            $btn = $menu->createButton();
            $btn->setMetaObject($data_sheet->getMetaObject());
            $btn->setActionAlias('exface.Core.ObjectBasketShowDialog');
            $btn->setCaption($data_sheet->countRows() . 'x ' . $data_sheet->getMetaObject()->getName());
            
            $btn->getAction()
                ->setMetaObject($data_sheet->getMetaObject())
                ->setContextScope($this->getScope()->getName())
                ->setContextAlias($this->getAliasWithNamespace())
                ->setPrefillWithInputData(false);
            $menu->addButton($btn);
        }
        
        // TODO add button to remove from basket here
        
        $container->addWidget($menu);
        
        return $container;
    }
    
    public function getName(){
        return $this->getWorkbench()->getCoreApp()->getTranslator()->translate('CONTEXT.OBJECTBASKET.NAME');
    }
}
?>