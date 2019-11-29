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
     * @return void
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
}
