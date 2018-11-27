<?php

namespace Exceedone\Exment\Enums;

class ColumnType extends EnumBase
{
    const TEXT = 'text';
    const TEXTAREA = 'textarea';
    const URL = 'url';
    const EMAIL = 'email';
    const INTEGER = 'integer';
    const DECIMAL = 'decimal';
    const CURRENCY = 'currency';
    const DATE = 'date';
    const TIME = 'time';
    const DATETIME = 'datetime';
    const SELECT = 'select';
    const SELECT_VALTEXT = 'select_valtext';
    const SELECT_TABLE = 'select_table';
    const YESNO = 'yesno';
    const BOOLEAN = 'boolean';
    const AUTO_NUMBER = 'auto_number';
    const IMAGE = 'image';
    const FILE = 'file';
    const USER = 'user';
    const ORGANIZATION = 'organization';

    public static function COLUMN_TYPE_CALC(){
        return [
            "integer",
            "decimal",
            "currency",
        ];
    }
}
