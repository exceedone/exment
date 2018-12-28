<?php

namespace Exceedone\Exment\Enums;

class ColumnType extends EnumBase
{
    const TEXT = 'text';
    const TEXTAREA = 'textarea';
    const EDITOR = 'editor';
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

    public static function COLUMN_TYPE_URL(){
        return [
            ColumnType::SELECT_TABLE,
            ColumnType::USER,
            ColumnType::ORGANIZATION,
            ColumnType::URL,
        ];
    }

    public static function COLUMN_TYPE_SELECT_TABLE(){
        return [
            ColumnType::SELECT_TABLE,
            ColumnType::USER,
            ColumnType::ORGANIZATION,
        ];
    }

    public static function COLUMN_TYPE_MULTIPLE_ENABLED(){
        return [
            ColumnType::SELECT,
            ColumnType::SELECT_VALTEXT,
            ColumnType::SELECT_TABLE,
            ColumnType::USER,
            ColumnType::ORGANIZATION,
        ];
    }

    public static function COLUMN_TYPE_SHOW_NOT_ESCAPE(){
        return [
            ColumnType::URL, 
            ColumnType::SELECT_TABLE, 
            ColumnType::EDITOR,
            ColumnType::USER,
            ColumnType::ORGANIZATION,
        ];
    }

    public static function isCalc($column_type){
        return in_array($column_type, static::COLUMN_TYPE_CALC());
    }
    
    public static function isUrl($column_type){
        return in_array($column_type, static::COLUMN_TYPE_URL());
    }
    
    public static function isSelectTable($column_type){
        return in_array($column_type, static::COLUMN_TYPE_SELECT_TABLE());
    }
    public static function isMultipleEnabled($column_type){
        return in_array($column_type, static::COLUMN_TYPE_MULTIPLE_ENABLED());
    }
    public static function isNotEscape($column_type){
        return in_array($column_type, static::COLUMN_TYPE_SHOW_NOT_ESCAPE());
    }
}
