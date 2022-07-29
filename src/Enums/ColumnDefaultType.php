<?php

namespace Exceedone\Exment\Enums;

class ColumnDefaultType extends EnumBase
{
    public const EXECUTING_DATE = 'executing_date';
    public const SELECT_DATE = 'select_date';

    public const EXECUTING_TIME = 'executing_time';
    public const SELECT_TIME = 'select_time';

    public const EXECUTING_DATETIME = 'executing_datetime';
    public const EXECUTING_TODAY = 'executing_today';
    public const SELECT_DATETIME = 'select_datetime';

    public const LOGIN_USER = 'login_user';
    public const SELECT_USER = 'select_user';


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
