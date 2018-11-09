<?php
namespace exface\Core\Templates\AbstractAjaxTemplate\Elements;

use exface\Core\Templates\AbstractAjaxTemplate\Interfaces\JsValueDecoratingInterface;
use exface\Core\Widgets\ProgressBar;

/**
 *
 * @method ProgressBar getWidget()
 *        
 * @author Andrej Kabachnik
 *        
 */
trait HtmlProgressBarTrait
{
    use JqueryAlignmentTrait;
    
    /**
     * Returns the <img> HTML tag with the given source.
     * 
     * @param string $src
     * @return string
     */
    protected function buildHtmlProgressBar($value = null, string $text = null, $progress = null, string $color = null) : string
    {
        $widget = $this->getWidget();
        $style = '';
        if (! $widget->getWidth()->isUndefined()) {
            $style .= 'width:' . $this->getWidth() . '; ';
        }
        if (! $widget->getHeight()->isUndefined()) {
            $style .= 'height: ' . $this->getHeight() . '; ';
        }
        
        if ($text === null && $value !== null) {
            $text = $value;
        }
        $progress = $progress ?? $widget->getMin();
        $color = $color ?? 'transparent';
        
        $output = <<<HTML

<div class="exf-progressbar" style="width:100%; border:1px solid #ccc; position:relative; overflow: hidden; white-space:nowrap; color:transparent; {$style}">{$text}
    <div class="exf-progressbar-bar" style="position: absolute; left:0; top:0; width:{$progress}%; background:{$color};">&nbsp;</div>
    <div class="exf-progressbar-text" style="position:absolute; left:0; top:0; z-index:100; padding:0 0; width:100%; color:initial; text-align: {$this->buildCssTextAlignValue($widget->getAlign())}">{$text}</div>
</div>

HTML;
        return $output;
    }
    
    /**
     * {@inheritdoc}
     * @see JsValueDecoratingInterface::buildJsValueDecorator
     */
    public function buildJsValueDecorator($value_js)
    {
        $widget = $this->getWidget();
        $colorMapJs = json_encode($widget->getColorMap());
        $textMapJs = json_encode($widget->getTextMap());
        $tpl = json_encode($this->buildHtmlProgressBar('exfph-val', 'exfph-text', 'exfph-progress', 'exfph-color'));
        return <<<JS
function() {
    var val = {$value_js};
    
    if (val === undefined || val === '') return '';

    var colorMap = {$colorMapJs};
    var textMap = {$textMapJs};
    var html = {$tpl};
    var numVal = parseFloat(val);    
    var color = 'transparent';    

    for (var t in colorMap) {
        if (numVal <= t) {
            color = colorMap[t];
            break;
        }
    }
    
    html = html
        .replace(/exfph-val/g, val)
        .replace("exfph-progress", ((numVal / {$widget->getMax()} - {$widget->getMin()}) * 100))
        .replace("exfph-color", color);

    if (textMap.length > 0) {
        html = html.replace(/exfph-text/g, textMap[val]);
    } else {
        var text = {$this->buildJsValueFormatter('val')};
        html = html.replace(/exfph-text/g, text);
    }
    
    return html;
}()
JS;
    }
}
?>