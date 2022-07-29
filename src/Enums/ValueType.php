<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\ColumnItems\ItemInterface;

/**
 * ValueType
 */
class ValueType extends EnumBase
{
    public const VALUE = 'value';
    public const HTML = 'html';
    public const TEXT = 'text';
    public const PURE_VALUE = 'pure_value';

    /**
     * Get custom value val
     *
     * @return mixed
     */
    public function getCustomValue(?ItemInterface $item, ?CustomValue $custom_value)
    {
        if (!isset($item) || !isset($custom_value)) {
            return null;
        }

        $item->setCustomValue($custom_value);

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
     * Filter ApiValueType.
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

    /**
     * Wheether re-get and set custom value.
     *
     * @return boolean
     */
    public static function isRegetApiCustomValue($valueType)
    {
        $enum = static::getEnum($valueType);
        switch ($enum) {
            case static::TEXT:
                return true;
        }

        return false;
    }
}
