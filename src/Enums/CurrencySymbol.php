<?php

namespace Exceedone\Exment\Enums;

class CurrencySymbol extends EnumBase
{
    public const JPY1 = 'JPY1';
    public const JPY2 = 'JPY2';
    public const USD = 'USD';
    public const EUR = 'EUR';

    protected static $options = [
        'JPY1' => ['text' => '¥', 'html' => '&yen;', 'type' => 'before'],
        'JPY2' => ['text' => '円', 'html' => '円', 'type' => 'after'],
        'USD' => ['text' => '$', 'html' => '$', 'type' => 'before'],
        'EUR' => ['text' => '€', 'html' => '&euro;', 'type' => 'after'],
    ];

    public function getOption()
    {
        return array_get(static::$options, $this->getValue());
    }

    public static function getEnum($value, $default = null)
    {
        $enum = parent::getEnum($value, $default);
        if (isset($enum)) {
            return $enum;
        }

        foreach (self::$options as $key => $v) {
            if (array_get($v, 'text') == $value || array_get($v, 'html') == $value) {
                return parent::getEnum($key);
            }
        }
        return $default;
    }
}
