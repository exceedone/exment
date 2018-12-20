<?php

namespace Exceedone\Exment\Enums;

class AuthorityValue extends EnumBase
{
    const SYSTEM = 'system';
    const CUSTOM_TABLE = 'custom_table';
    const CUSTOM_FORM = 'custom_form';
    const CUSTOM_VIEW = 'custom_view';
    const CUSTOM_VALUE_EDIT_ALL = 'custom_value_edit_all';
    const CUSTOM_VALUE_VIEW_ALL = 'custom_value_view_all';
    const CUSTOM_VALUE_EDIT = 'custom_value_edit';
    const CUSTOM_VALUE_VIEW = 'custom_value_view';
    const PLUGIN_ACCESS = 'plugin_access';
    const PLUGIN_SETTING = 'plugin_setting';

    public const AVAILABLE_ACCESS_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL, self::CUSTOM_VALUE_EDIT, self::CUSTOM_VALUE_VIEW]; 
    public const AVAILABLE_EDIT_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_EDIT];
    public const AVAILABLE_ALL_CUSTOM_VALUE = [self::CUSTOM_VALUE_EDIT_ALL, self::CUSTOM_VALUE_VIEW_ALL];
}
