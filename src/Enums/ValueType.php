<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\ColumnItems\CustomItem;

/**
 * ValueType
 */
class ValueType extends EnumBase
{
    const VALUE = 'value';
    const HTML = 'html';
    const TEXT = 'text';
    const PURE_VALUE = 'pure_value';

    /**
     * Get custom value val
     *
     * @return mixed
     */
    public function getCustomValue(?CustomItem $item, ?CustomValue $custom_value)
    {
        if (!isset($item) || !isset($custom_value)) {
            return null;
        }

        switch ($this) {
            case static::VALUE:
                return $item->value();
                
            case static::HTML:
                return $item->html();
            
            case static::TEXT:
                return $item->text();
    
            case static::PURE_VALUE:
                return $item->pureValue();
        }

        return null;
    }

    /**
     * Filter ApiValueType. Now Only text.
     *
     * @return boolean
     */
    public static function filterApiValueType($valueType)
    {
        $enum = static::getEnum($valueType);
        switch ($enum) {
            case static::TEXT:
            case static::PURE_VALUE:
                return true;
        }

        return false;
    }
}
