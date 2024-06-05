<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\DashboardBoxItems\SystemItems;

class DashboardBoxSystemPage extends EnumBase
{
    public const GUIDELINE = 1;
    public const NEWS = 2;
    public const EDITOR = 3;
    public const HTML = 4;
    public const NOTIFY_NAVBAR = 4;

    protected static $options = [
        'guideline' => ['id' => 1, 'name' => 'guideline', 'class' => SystemItems\Guideline::class],
        'news' => ['id' => 2, 'name' => 'news', 'class' => SystemItems\News::class],
        'editor' => ['id' => 3, 'name' => 'editor', 'class' => SystemItems\Editor::class],
        'html' => ['id' => 4, 'name' => 'html', 'class' => SystemItems\Html::class],
        'notify_navbar' => ['id' => 5, 'name' => 'notify_navbar', 'class' => SystemItems\NotifyNavbar::class],
        'qr_code' => ['id' => 6, 'name' => 'qr_code', 'class' => SystemItems\QRCode::class],
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
