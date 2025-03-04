<?php
namespace exface\Core\DataTypes;

use exface\Core\Interfaces\DataTypes\EnumDataTypeInterface;
use exface\Core\CommonLogic\UxonObject;

/**
 * Enumeration of currently installed locales: en_EN, de_DE, etc.
 * 
 * @author Andrej Kabachnik
 *
 */
class LocaleDataType extends StringDataType implements EnumDataTypeInterface
{
    private $locales = null;
    
    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabelOfValue()
     */
    public function getLabelOfValue($value = null, string $inLocale = null): string
    {
        if ($inLocale === null) {
            $inLocale = $this->getWorkbench()->getContext()->getScopeSession()->getSessionLocale();
        }
        return self::getLocaleName($value, $inLocale); 
    }
    
    /**
     * Returns the localized name of the locale - e.g. "German" for "de" if $inLocal is "en".
     * 
     * @param string $locale
     * @param string $inLocale
     * @return string
     */
    public static function getLocaleName(string $locale, string $inLocale) : ?string
    {
        return \Locale::getDisplayName($locale, $inLocale);
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getLabels()
     */
    public function getLabels()
    {
        if ($this->locales === null) {
            $currentLocale = $this->getWorkbench()->getContext()->getScopeSession()->getSessionLocale();
            $defaultLocale = $this->getWorkbench()->getConfig()->getOption('SERVER.DEFAULT_LOCALE');
            $this->locales = [
                $currentLocale => $this->getLabelOfValue($currentLocale, $currentLocale),
                $defaultLocale => $this->getLabelOfValue($defaultLocale, $currentLocale)
            ];
            foreach ($this->getWorkbench()->getCoreApp()->getLanguages() as $locale) {
                $this->locales[$locale] = $this->getLabelOfValue($locale, $currentLocale);
            }
            asort($this->locales);
        }
        return $this->locales;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::setValues()
     */
    public function setValues($uxon_or_array)
    {
        if ($uxon_or_array instanceof UxonObject) {
            $array = $uxon_or_array->toArray();
        } else {
            $array = $uxon_or_array;
        }
        $this->locales = array_merge($this->getLabels(), $array);
        return $this;
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::toArray()
     */
    public function toArray(): array
    {
        return $this->getLabels();
    }

    /**
     * 
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getValues()
     */
    public function getValues()
    {
        return array_keys($this->getLabels());
    }
    
    /**
     * Returns all locales supported by the current version of PHP
     * 
     * @return string[]
     */
    public static function getAllLocales() : array
    {
        return \ResourceBundle::getLocales('');
    }
    
    /**
     * Returns the language from the given locale - e.g. `de` from `de_DE`
     * 
     * @param string $locale
     * @return string|NULL
     */
    public static function findLanguage(string $locale) : ?string
    {
        return \Locale::getPrimaryLanguage($locale);
    }
    
    /**
     * Returns the region from the given locale - e.g. `AT` from `de_AT`
     * 
     * @param string $locale
     * @return string|NULL
     */
    public static function findRegion(string $locale) : ?string
    {
        return \Locale::getRegion($locale);
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getValueHints()
     */
    public function getValueHints() : array
    {
        return [];
    }

    /**
     *
     * {@inheritDoc}
     * @see \exface\Core\Interfaces\DataTypes\EnumDataTypeInterface::getHintOfValue()
     */
    public function getHintOfValue($value) : ?string
    {
        return null;
    }
}