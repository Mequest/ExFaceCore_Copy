<?php
namespace exface\Core\Interfaces;

use exface\Core\CommonLogic\UxonObject;
use exface\Core\Widgets\ContextBar;

interface UiPageInterface extends ExfaceClassInterface
{

    /**
     *
     * @param string $widget_type            
     * @param WidgetInterface $parent_widget            
     * @param string $widget_id            
     * @return WidgetInterface
     */
    public function createWidget($widget_type, WidgetInterface $parent_widget = null, UxonObject $uxon = null);

    /**
     *
     * @return \exface\Core\Interfaces\WidgetInterface
     */
    public function getWidgetRoot();

    /**
     * Returns the widget with the given id from this page or FALSE if no matching widget could be found.
     * The search
     * can optionally be restricted to the children of another widget.
     *
     * @param string $widget_id            
     * @param WidgetInterface $parent            
     * @return WidgetInterface|null
     */
    public function getWidget($widget_id, WidgetInterface $parent = null);

    /**
     *
     * @return string
     */
    public function getId();

    /**
     *
     * @param string $value            
     * @return \exface\Core\CommonLogic\UiPage
     */
    public function setId($value);

    /**
     * Removes the widget with the given id from this page.
     * This will not remove child widgets!
     *
     * @see remove_widget() for a more convenient alternative optionally removing children too.
     *     
     * @param string $widget_id            
     * @param boolean $remove_children_too            
     * @return \exface\Core\CommonLogic\UiPage
     */
    public function removeWidgetById($widget_id);

    /**
     * Removes a widget from the page.
     * By default all children are removed too.
     *
     * Note, that if the widget has a parent and that parent still is on this page, the widget
     * will merely be removed from cache, but will still be accessible through page::getWidget().
     *
     * @param WidgetInterface $widget            
     * @return UiPageInterface
     */
    public function removeWidget(WidgetInterface $widget, $remove_children_too = true);

    /**
     *
     * @return UiManagerInterface
     */
    public function getUi();

    /**
     *
     * @return string
     */
    public function getWidgetIdSeparator();

    /**
     *
     * @return string
     */
    public function getWidgetIdSpaceSeparator();

    /**
     * Returns TRUE if the page does not have widgets and FALSE if there is at least one widget.
     *
     * @return boolean
     */
    public function isEmpty();
    
    /**
     * Returns the context bar widget for this page
     * 
     * @return ContextBar
     */
    public function getContextBar();
}

?>
