<?php

namespace Exceedone\Exment\Enums;

class ColumnDefaultType extends EnumBase
{
    const EXECUTING_DATE = 'executing_date';
    const SELECT_DATE = 'select_date';
    
    const EXECUTING_TIME = 'executing_time';
    const SELECT_TIME = 'select_time';
    
    const EXECUTING_DATETIME = 'executing_datetime';
    const EXECUTING_TODAY = 'executing_today';
    const SELECT_DATETIME = 'select_datetime';
    
    const LOGIN_USER = 'login_user';
    const SELECT_USER = 'select_user';


    public static function COLUMN_DEFAULT_TYPE_DATE()
    {
        return [
            ColumnDefaultType::EXECUTING_DATE,
            ColumnDefaultType::SELECT_DATE,
        ];
    }

    public static function COLUMN_DEFAULT_TYPE_TIME()
    {
        return [
            ColumnDefaultType::EXECUTING_TIME,
            ColumnDefaultType::SELECT_TIME,
        ];
    }

    public static function COLUMN_DEFAULT_TYPE_DATETIME()
    {
        return [
            ColumnDefaultType::EXECUTING_DATETIME,
            ColumnDefaultType::EXECUTING_TODAY,
            ColumnDefaultType::SELECT_DATETIME,
        ];
    }
    
    public static function COLUMN_DEFAULT_TYPE_USER()
    {
        return [
            ColumnDefaultType::LOGIN_USER,
            ColumnDefaultType::SELECT_USER,
        ];
    }
}
