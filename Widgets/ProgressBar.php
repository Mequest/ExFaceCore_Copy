<?php
namespace exface\Core\Widgets;

use exface\Core\Widgets\Traits\iCanBeAlignedTrait;
use exface\Core\Interfaces\Widgets\iCanBeAligned;
use exface\Core\DataTypes\NumberDataType;
use exface\Core\CommonLogic\UxonObject;
use exface\Core\Interfaces\DataSheets\DataSheetInterface;
use exface\Core\CommonLogic\DataSheets\DataColumn;
use exface\Core\Interfaces\Model\MetaAttributeInterface;
use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;

/**
 * Displays the widgets value as a progress bar with a floating text label.
 * 
 * The progress bar can be configured by setting `min`/`max` values, a `color_scale`
 * and a `text_map` to add a text to the value. 
 * 
 * By default, a percentual scale (from 0 to 100) with a yellow-green color gradient will be used.
 * 
 * ## Examples
 * 
 * Progress bar with a single color:
 * 
 * ```
 *  {
 *      "widget_type": "ProgressBar",
 *      "color": "lightgray"
 *  }
 * 
 * ```
 * 
 * Custom colors for certain values:
 * 
 * ```
 *  {
 *      "widget_type": "ProgressBar",
 *      "color_scale": {
 *          "50": "~ERROR",
 *          "100": "lightgray"
 *      }
 *  }
 * 
 * ```
 * 
 * Value of another attribute as text (instead of the percent value):
 * 
 * ```
 *  {
 *      "widget_type": "ProgressBar",
 *      "attribute_alias": "PROGRESS",
 *      "text_attribute_alias": "STATUS__NAME"
 *  }
 * 
 * ```
 *
 * @author Andrej Kabachnik
 *        
 */
class ProgressBar extends Display implements iCanBeAligned
{
    use iCanBeAlignedTrait {
        getAlign as getAlignViaTrait;
    }
    private $min = 0;
    
    private $max = 100;
    
    private $textMap = null;
    
    private $textAttributeAlias = null;
    
    private $textStaticValue = null;
    
    /**
     *
     * @return int
     */
    public function getMin()
    {
        return $this->min;
    }
    
    /**
     * Sets the minimum (leftmost) value  - 0 by defaul
     * 
     * @uxon-property min
     * @uxon-type number
     * @uxon-default 0
     * 
     * @param int $value
     * @return ProgressBar
     */
    public function setMin($value) : ProgressBar
    {
        $this->min = NumberDataType::cast($value);
        return $this;
    }
    
    /**
     *
     * @return number
     */
    public function getMax()
    {
        return $this->max;
    }
    
    /**
     * Sets the maximum (rightmost) value - 100 by default
     * 
     * @uxon-property max
     * @uxon-type number
     * @uxon-default 100
     * 
     * @param number $value
     * @return ProgressBar
     */
    public function setMax($value) : ProgressBar
    {
        $this->max = NumberDataType::cast($value);
        return $this;
    }
    
    /**
     *
     * @return array
     */
    public function getTextScale() : array
    {
        return $this->textMap ?? [];
    }
    
    /**
     * 
     * @param string $value
     * @return string|NULL
     */
    public function getTextForValue(string $value) : ?string
    {
        if ($this->textStaticValue !== null) {
            return $this->textStaticValue;
        }
        return static::findText($value, $this->getTextScale());
    }
    
    /**
     * 
     * @return bool
     */
    public function hasTextScale() : bool
    {
        return $this->textMap !== null;
    }
    
    /**
     * Specify custom labels for certain values.
     * 
     * ```
     * {
     *  "10": "Pending",
     *  "20": "In Progress"
     *  "90": "Canceled",
     *  "100" : "Finished"
     * }
     * 
     * ```
     * 
     * @uxon-property text_map
     * @uxon-type object
     * @uxon-template {"10": "Pending", "20": "In Progress", "90": "Canceled", "100" : "Finished"}
     * 
     * @param UxonObject $value
     * @return ProgressBar
     */
    public function setTextScale(UxonObject $value) : ProgressBar
    {
        $this->textMap = $value->toArray();
        return $this;
    }
    
    /**
     * 
     * @param float $value
     * @param array $textMap
     * @return string|NULL
     */
    public static function findText(float $value, array $textMap = null) : ?string
    {
        if (empty($textMap)) {
            return $value;
        }
        
        ksort($textMap);
        foreach ($textMap as $max => $text) {
            if ($value <= $max) {
                return $text;
            }
        }
        return $text;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iHaveColorScale::getColorScale()
     */
    public function getColorScale() : array
    {
        $scale = parent::getColorScale();
        return empty($scale) && $this->getColor() === null ? static::getColorScaleDefault($this->getMin(), $this->getMax()) : $scale;
    }
    
    /**
     * Returns the default color map
     * 
     * @param float $min
     * @param float $max
     * @param bool $invert
     * @return array
     */
    public static function getColorScaleDefault(float $min = 0, float $max = 100, bool $invert = false) : array
    {
        $range = $max - $min;
        $m = $range / 100;
        $map = [
            $m*10 => "#FFEF9C",
            $m*20 => "#EEEA99",
            $m*30 => "#DDE595",
            $m*40 => "#CBDF91",
            $m*50 => "#BADA8E",
            $m*60 => "#A9D48A",
            $m*70 => "#97CF86",
            $m*80 => "#86C983",
            $m*90 => "#75C47F",
            $m*100 => "#63BE7B",
            $m*110 => "orange"
        ];
        
        return $invert === false ? $map : array_reverse($map);
    }
    
    /**
     * The text over the progress bar gets opposite alignment automatically if the value is a number
     * and there is no text_map (which would make it become text).
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iCanBeAligned::getAlign()
     */
    public function getAlign()
    {
        if ($this->isAlignSet() === true) {
            return $this->getAlignViaTrait();
        }
        
        if ($this->hasTextScale() === false && ($this->getValueDataType() instanceof NumberDataType) && ! ($this->getValueDataType() instanceof EnumDataTypeInterface)) {
            return EXF_ALIGN_OPPOSITE;
        }
        
        return EXF_ALIGN_DEFAULT;
    }
    
    /**
     * 
     * @param float $value
     * @return float
     */
    public function getProgress(float $value) : float
    {
        $range = $this->getMax() - $this->getMin();
        return $value / $range * 100;
    }
    
    /**
     *
     * @return string
     */
    public function getTextAttributeAlias() : string
    {
        return $this->textAttributeAlias;
    }
    
    /**
     * Makes the progressbar show the value of a different attribute than the one used for the progress.
     * 
     * @uxon-property text_attribute_alias
     * @uxon-type metamodel:attribute
     * 
     * @param string $value
     * @return ProgressBar
     */
    public function setTextAttributeAlias(string $value) : ProgressBar
    {
        $this->textAttributeAlias = $value;
        return $this;
    }
    
    public function isTextBoundToAttribute() : bool
    {
        return $this->textAttributeAlias !== null;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\Value::prepareDataSheetToRead()
     */
    public function prepareDataSheetToRead(DataSheetInterface $data_sheet = null)
    {
        $data_sheet = parent::prepareDataSheetToRead($data_sheet);
        if ($this->isTextBoundToAttribute() === true) {
            $textPrefillExpr = $this->getPrefillExpression($data_sheet, $this->getMetaObject(), $this->getTextAttributeAlias());
            if ($textPrefillExpr !== null) {
                $data_sheet->getColumns()->addFromExpression($textPrefillExpr);
            }
        }
        return $data_sheet;
    }
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Widgets\Value::doPrefill()
     */
    protected function doPrefill(DataSheetInterface $data_sheet)
    {
        parent::doPrefill($data_sheet);
        if ($this->isTextBoundToAttribute() === true) {
            if (null !== $expr = $this->getPrefillExpression($data_sheet, $this->getMetaObject(), $this->getTextAttributeAlias())) {
                $this->doPrefillForExpression($data_sheet, $expr, 'text', function($value){
                    $this->setText($value ?? '');
                });
            }
        }
        return;
    }
    
    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\Widgets\iShowDataColumn::getDataColumnName()
     */
    public function getTextDataColumnName()
    {
        return $this->isTextBoundToAttribute() ? DataColumn::sanitizeColumnName($this->getTextAttributeAlias()) : $this->getDataColumnName();
    }
    
    /**
     * 
     * @return MetaAttributeInterface
     */
    public function getTextAttribute() : MetaAttributeInterface
    {
        if ($this->isTextBoundToAttribute() === true) {
            return $this->getMetaObject()->getAttribute($this->getTextAttributeAlias());
        }
        return $this->getAttribute();
    }
    
    /**
     * 
     * @param string $value
     * @return ProgressBar
     */
    protected function setText(string $value) : ProgressBar
    {
        $this->textStaticValue = $value;
        return $this;
    }
    
    /**
     * 
     * @return string|NULL
     */
    public function getText() : ?string
    {
        if ($this->textStaticValue !== null) {
            return $this->textStaticValue;   
        }
        if ($this->hasTextScale() && $this->hasValue()) {
            static::findText($this->getValue(), $this->getTextScale());
        }
        return null;
    }
}