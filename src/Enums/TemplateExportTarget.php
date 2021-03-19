<?php

namespace Exceedone\Exment\Enums;

class TemplateExportTarget extends EnumBase
{
    const TABLE = 'table';
    const MENU = 'menu';
    const DASHBOARD = 'dashboard';
    const ROLE_GROUP = 'role_group';
    const PUBLIC_FORM = 'public_form';

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
