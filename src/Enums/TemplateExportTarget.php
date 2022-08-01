<?php

namespace Exceedone\Exment\Enums;

class TemplateExportTarget extends EnumBase
{
    public const TABLE = 'table';
    public const MENU = 'menu';
    public const DASHBOARD = 'dashboard';
    public const ROLE_GROUP = 'role_group';
    public const PUBLIC_FORM = 'public_form';

    public static function TEMPLATE_EXPORT_OPTIONS()
    {
        return [
            static::TABLE,
            static::MENU,
            static::DASHBOARD,
            static::ROLE_GROUP,
        ];
    }
}
