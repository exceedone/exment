<?php

namespace Exceedone\Exment\Enums;

class Authority extends EnumBase
{
    const SYSTEM = [
        AuthorityValue::SYSTEM,
        AuthorityValue::CUSTOM_TABLE,
        AuthorityValue::CUSTOM_FORM,
        AuthorityValue::CUSTOM_VIEW,
        AuthorityValue::CUSTOM_VALUE_EDIT_ALL,
    ];
    const TABLE = [
        AuthorityValue::CUSTOM_TABLE,
        AuthorityValue::CUSTOM_FORM,
        AuthorityValue::CUSTOM_VIEW,
        AuthorityValue::CUSTOM_VALUE_EDIT_ALL,
        AuthorityValue::CUSTOM_VALUE_EDIT,
        AuthorityValue::CUSTOM_VALUE_VIEW,
    ];
    const VALUE = [
        AuthorityValue::CUSTOM_VALUE_EDIT,
        AuthorityValue::CUSTOM_VALUE_VIEW,
    ];
    const PLUGIN = [
        AuthorityValue::PLUGIN_ACCESS,
        AuthorityValue::PLUGIN_SETTING,
    ];

    public static function getAuthorityType($autority_type){
        return static::values()[strtoupper($autority_type)]->value;
    }
}
