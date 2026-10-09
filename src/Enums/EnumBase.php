<?php

namespace Exceedone\Exment\Enums;

use MyCLabs\Enum\Enum;

// @phpstan-ignore-next-line
class EnumBase extends Enum
{
    /**
     * get lower key Name
     */
    // @phpstan-ignore-next-line
    public function lowerKey()
    {
        return strtolower($this->getKey());
    }

    /**
     * get upper key Name
     */
    // @phpstan-ignore-next-line
    public function upperKey()
    {
        return strtoupper($this->getKey());
    }

    // @phpstan-ignore-next-line
    public function toString()
    {
        return $this->__toString();
    }

    /**
     * convert trans Array.
     * value is enum value.
     * text translates using enum value.
     */
    // @phpstan-ignore-next-line
    public static function transArray($base_key, $isExment = true)
    {
        return getTransArray(static::arrays(), $base_key, $isExment);
    }

    /**
     * convert trans Array.
     * value is enum value.
     * text translates using enum value.
     */
    // @phpstan-ignore-next-line
    public static function transArrayFilter($base_key, $targetEnums, $isExment = true)
    {
        $arrays = collect(static::arrays())->filter(function ($arr) use ($targetEnums) {
            return in_array($arr, $targetEnums);
        })->toArray();
        return getTransArray($arrays, $base_key, $isExment);
    }

    /**
     * convert trans Array.
     * value is enum value.
     * text translates using enum key.
     */
    // @phpstan-ignore-next-line
    public static function transKeyArray($base_key, $isExment = true)
    {
        $array = [];
        foreach (static::toArray() as $key => $value) {
            $array[$value] = strtolower($key);
        }
        return getTransArrayValue($array, $base_key, $isExment);
    }

    /**
     * convert trans Array.
     * value is enum value.
     * text translates using enum key.
     */
    // @phpstan-ignore-next-line
    public static function transKeyArrayFilter($base_key, $targetEnums, $isExment = true)
    {
        $array = [];
        foreach (static::toArray() as $key => $value) {
            if (!in_array($value, $targetEnums)) {
                continue;
            }
            $array[$value] = strtolower($key);
        }
        return getTransArrayValue($array, $base_key, $isExment);
    }

    /**
     * convert trans. use enum key (and convert key snake_case)
     */
    // @phpstan-ignore-next-line
    public function transKey($base_key, $isExment = true)
    {
        $key = $base_key.'.'.$this->lowerKey();
        if ($isExment) {
            return exmtrans($key);
        }
        return trans($key);
    }

    // @phpstan-ignore-next-line
    public static function arrays()
    {
        return array_flatten(static::toArray());
    }

    // @phpstan-ignore-next-line
    public static function getEnum($value, $default = null)
    {
        if ($value instanceof Enum) {
            return $value;
        }

        $enums = static::values();
        foreach ($enums as $enum) {
            if ($enum->toString() == $value) {
                return $enum;
            }

            $key = $enum->lowerKey();
            if (isMatchString($key, $value)) {
                return $enum;
            }
        }

        // if if int, return as enum
        if (is_int($default)) {
            return self::getEnum($default);
        }
        return $default;
    }

    /**
     * get enum, and return value
     */
    // @phpstan-ignore-next-line
    public static function getEnumValue($value, $default = null)
    {
        $enum = static::getEnum($value, $default);
        return isset($enum) ? $enum->getValue() : $default;
    }
}
