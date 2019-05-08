<?php

namespace Exceedone\Exment\Enums;

class ViewColumnFilterOption extends EnumBase
{
    const EQ = 1;
    const NE = 2;
    const NOT_NULL = 3;
    const NULL = 4;

    const DAY_ON = 1001;
    const DAY_ON_OR_AFTER = 1002;
    const DAY_ON_OR_BEFORE = 1003;
    const DAY_NOT_NULL = 1004;
    const DAY_NULL = 1005;
    const DAY_TODAY = 1011;
    const DAY_TODAY_OR_AFTER = 1012;
    const DAY_TODAY_OR_BEFORE = 1013;
    const DAY_YESTERDAY = 1014;
    const DAY_TOMORROW = 1015;
    const DAY_THIS_MONTH = 1021;
    const DAY_LAST_MONTH = 1022;
    const DAY_NEXT_MONTH = 1023;
    const DAY_THIS_YEAR = 1031;
    const DAY_LAST_YEAR = 1032;
    const DAY_NEXT_YEAR = 1033;

    const DAY_LAST_X_DAY_OR_AFTER = 1041;
    const DAY_LAST_X_DAY_OR_BEFORE = 1042;
    const DAY_NEXT_X_DAY_OR_AFTER = 1043;
    const DAY_NEXT_X_DAY_OR_BEFORE = 1044;
    
    const USER_EQ = 2001;
    const USER_NE = 2002;
    const USER_NOT_NULL = 2003;
    const USER_NULL = 2004;
    const USER_EQ_USER = 2011;
    const USER_NE_USER = 2012;

    const NUMBER_GT = 3001;
    const NUMBER_LT = 3002;
    const NUMBER_GTE = 3003;
    const NUMBER_LTE = 3004;

    const SELECT_EXISTS = 4001;
    const SELECT_NOT_EXISTS = 4002;

    public static function VIEW_COLUMN_VALUE_TYPE($filter_option)
    {
        switch($filter_option) {
            case ViewColumnFilterOption::DAY_ON:
            case ViewColumnFilterOption::DAY_ON_OR_AFTER:
            case ViewColumnFilterOption::DAY_ON_OR_BEFORE:
                return ColumnType::DATE;
            case ViewColumnFilterOption::USER_EQ:
            case ViewColumnFilterOption::USER_NE:
            case ViewColumnFilterOption::EQ:
            case ViewColumnFilterOption::NE:
            case ViewColumnFilterOption::NUMBER_GT:
            case ViewColumnFilterOption::NUMBER_LT:
            case ViewColumnFilterOption::NUMBER_GTE:
            case ViewColumnFilterOption::NUMBER_LTE:
                return null;
            case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_LAST_X_DAY_OR_BEFORE:
            case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER:
            case ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                return ColumnType::TEXT;
            case ViewColumnFilterOption::SELECT_EXISTS:
            case ViewColumnFilterOption::SELECT_NOT_EXISTS:
                return ColumnType::SELECT;
            default:
                return 'none';
        }
    }
    public static function VIEW_COLUMN_FILTER_OPTIONS()
    {
        return [
            ViewColumnFilterType::DEFAULT => [
                ['id' => ViewColumnFilterOption::EQ, 'name' => 'eq'],
                ['id' => ViewColumnFilterOption::NE, 'name' => 'ne'],
                ['id' => ViewColumnFilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => ViewColumnFilterOption::NULL, 'name' => 'null'],
            ],
            ViewColumnFilterType::NUMBER => [
                ['id' => ViewColumnFilterOption::EQ, 'name' => 'eq'],
                ['id' => ViewColumnFilterOption::NE, 'name' => 'ne'],
                ['id' => ViewColumnFilterOption::NUMBER_GT, 'name' => 'gt'],
                ['id' => ViewColumnFilterOption::NUMBER_LT, 'name' => 'lt'],
                ['id' => ViewColumnFilterOption::NUMBER_GTE, 'name' => 'gte'],
                ['id' => ViewColumnFilterOption::NUMBER_LTE, 'name' => 'lte'],
                ['id' => ViewColumnFilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => ViewColumnFilterOption::NULL, 'name' => 'null'],
            ],
            ViewColumnFilterType::SELECT => [
                ['id' => ViewColumnFilterOption::SELECT_EXISTS, 'name' => 'select-eq'],
                ['id' => ViewColumnFilterOption::SELECT_NOT_EXISTS, 'name' => 'select-ne'],
                ['id' => ViewColumnFilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => ViewColumnFilterOption::NULL, 'name' => 'null'],
            ],
            ViewColumnFilterType::FILE => [
                ['id' => ViewColumnFilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => ViewColumnFilterOption::NULL, 'name' => 'null'],
            ],
            ViewColumnFilterType::DAY => [
                ['id' => ViewColumnFilterOption::DAY_ON, 'name' => 'on'],
                ['id' => ViewColumnFilterOption::DAY_ON_OR_AFTER, 'name' => 'on-or-after'],
                ['id' => ViewColumnFilterOption::DAY_ON_OR_BEFORE, 'name' => 'on-or-before'],
                ['id' => ViewColumnFilterOption::DAY_TODAY, 'name' => 'today'],
                ['id' => ViewColumnFilterOption::DAY_TODAY_OR_AFTER, 'name' => 'today-or-after'],
                ['id' => ViewColumnFilterOption::DAY_TODAY_OR_BEFORE, 'name' => 'today-or-before'],
                ['id' => ViewColumnFilterOption::DAY_YESTERDAY, 'name' => 'yesterday'],
                ['id' => ViewColumnFilterOption::DAY_TOMORROW, 'name' => 'tomorrow'],
                ['id' => ViewColumnFilterOption::DAY_THIS_MONTH, 'name' => 'this-month'],
                ['id' => ViewColumnFilterOption::DAY_LAST_MONTH, 'name' => 'last-month'],
                ['id' => ViewColumnFilterOption::DAY_NEXT_MONTH, 'name' => 'next-month'],
                ['id' => ViewColumnFilterOption::DAY_THIS_YEAR, 'name' => 'this-year'],
                ['id' => ViewColumnFilterOption::DAY_LAST_YEAR, 'name' => 'last-year'],
                ['id' => ViewColumnFilterOption::DAY_NEXT_YEAR, 'name' => 'next-year'],
                
                ['id' => ViewColumnFilterOption::DAY_LAST_X_DAY_OR_AFTER, 'name' => 'last-x-day-after'],
                ['id' => ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_AFTER, 'name' => 'next-x-day-after'],
                ['id' => ViewColumnFilterOption::DAY_LAST_X_DAY_OR_BEFORE, 'name' => 'last-x-day-or-before'],
                ['id' => ViewColumnFilterOption::DAY_NEXT_X_DAY_OR_BEFORE, 'name' => 'next-x-day-or-before'],
                
                ['id' => ViewColumnFilterOption::DAY_NOT_NULL, 'name' => 'not-null'],
                ['id' => ViewColumnFilterOption::DAY_NULL, 'name' => 'null'],
            ],
            ViewColumnFilterType::USER => [
                ['id' => ViewColumnFilterOption::USER_EQ_USER, 'name' => 'eq-user'],
                ['id' => ViewColumnFilterOption::USER_NE_USER, 'name' => 'ne-user'],
                ['id' => ViewColumnFilterOption::USER_EQ, 'name' => 'eq'],
                ['id' => ViewColumnFilterOption::USER_NE, 'name' => 'ne'],
                ['id' => ViewColumnFilterOption::USER_NOT_NULL, 'name' => 'not-null'],
                ['id' => ViewColumnFilterOption::USER_NULL, 'name' => 'null'],
            ],
        ];
    }
}
