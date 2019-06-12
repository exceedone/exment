<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\DashboardBoxItems\SystemItems;

class DashboardBoxSystemPage extends EnumBase
{
    const GUIDELINE = 1;

    protected static $options = [
        'guideline' => ['id' => 1, 'name' => 'guideline', 'class' => SystemItems\Guideline::class],
        'news' => ['id' => 2, 'name' => 'news', 'class' => SystemItems\News::class],
    ];

    public function option()
    {
        return array_get(static::$options, $this->lowerKey());
    }
    
    public static function options()
    {
        return static::$options;
    }
    
    public static function getEnum($value, $default = null)
    {
        $enum = parent::getEnum($value, $default);
        if (isset($enum)) {
            return $enum;
        }

        foreach (self::$options as $key => $v) {
            if (array_get($v, 'id') == $value) {
                return parent::getEnum($key);
            }
        }
        return $default;
    }
}
