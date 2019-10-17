<?php

namespace Exceedone\Exment\Enums;

class FilterOption extends EnumBase
{
    const EQ = 1;
    const NE = 2;
    const NOT_NULL = 3;
    const NULL = 4;
    const LIKE = 5;
    const NOT_LIKE = 6;

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

    public static function VALUE_TYPE($filter_option)
    {
        switch ($filter_option) {
            case FilterOption::DAY_ON:
            case FilterOption::DAY_ON_OR_AFTER:
            case FilterOption::DAY_ON_OR_BEFORE:
                return FilterType::DAY;
            case FilterOption::USER_EQ:
            case FilterOption::USER_NE:
            case FilterOption::EQ:
            case FilterOption::NE:
            case FilterOption::LIKE:
            case FilterOption::NOT_LIKE:
            case FilterOption::NUMBER_GT:
            case FilterOption::NUMBER_LT:
            case FilterOption::NUMBER_GTE:
            case FilterOption::NUMBER_LTE:
                return null;
            case FilterOption::DAY_LAST_X_DAY_OR_AFTER:
            case FilterOption::DAY_LAST_X_DAY_OR_BEFORE:
            case FilterOption::DAY_NEXT_X_DAY_OR_AFTER:
            case FilterOption::DAY_NEXT_X_DAY_OR_BEFORE:
                return FilterType::NUMBER;
            case FilterOption::SELECT_EXISTS:
            case FilterOption::SELECT_NOT_EXISTS:
                return FilterType::SELECT;
            default:
                return 'none';
        }
    }
    public static function FILTER_OPTIONS()
    {
        return [
            FilterType::DEFAULT => [
                ['id' => FilterOption::EQ, 'name' => 'eq'],
                ['id' => FilterOption::NE, 'name' => 'ne'],
                ['id' => FilterOption::LIKE, 'name' => 'like'],
                ['id' => FilterOption::NOT_LIKE, 'name' => 'not-like'],
                ['id' => FilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => FilterOption::NULL, 'name' => 'null'],
            ],
            FilterType::NUMBER => [
                ['id' => FilterOption::EQ, 'name' => 'eq'],
                ['id' => FilterOption::NE, 'name' => 'ne'],
                ['id' => FilterOption::NUMBER_GT, 'name' => 'gt'],
                ['id' => FilterOption::NUMBER_LT, 'name' => 'lt'],
                ['id' => FilterOption::NUMBER_GTE, 'name' => 'gte'],
                ['id' => FilterOption::NUMBER_LTE, 'name' => 'lte'],
                ['id' => FilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => FilterOption::NULL, 'name' => 'null'],
            ],
            FilterType::SELECT => [
                ['id' => FilterOption::SELECT_EXISTS, 'name' => 'select-eq'],
                ['id' => FilterOption::SELECT_NOT_EXISTS, 'name' => 'select-ne'],
                ['id' => FilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => FilterOption::NULL, 'name' => 'null'],
            ],
            FilterType::FILE => [
                ['id' => FilterOption::NOT_NULL, 'name' => 'not-null'],
                ['id' => FilterOption::NULL, 'name' => 'null'],
            ],
            FilterType::DAY => [
                ['id' => FilterOption::DAY_ON, 'name' => 'on'],
                ['id' => FilterOption::DAY_ON_OR_AFTER, 'name' => 'on-or-after'],
                ['id' => FilterOption::DAY_ON_OR_BEFORE, 'name' => 'on-or-before'],
                ['id' => FilterOption::DAY_TODAY, 'name' => 'today'],
                ['id' => FilterOption::DAY_TODAY_OR_AFTER, 'name' => 'today-or-after'],
                ['id' => FilterOption::DAY_TODAY_OR_BEFORE, 'name' => 'today-or-before'],
                ['id' => FilterOption::DAY_YESTERDAY, 'name' => 'yesterday'],
                ['id' => FilterOption::DAY_TOMORROW, 'name' => 'tomorrow'],
                ['id' => FilterOption::DAY_THIS_MONTH, 'name' => 'this-month'],
                ['id' => FilterOption::DAY_LAST_MONTH, 'name' => 'last-month'],
                ['id' => FilterOption::DAY_NEXT_MONTH, 'name' => 'next-month'],
                ['id' => FilterOption::DAY_THIS_YEAR, 'name' => 'this-year'],
                ['id' => FilterOption::DAY_LAST_YEAR, 'name' => 'last-year'],
                ['id' => FilterOption::DAY_NEXT_YEAR, 'name' => 'next-year'],
                
                ['id' => FilterOption::DAY_LAST_X_DAY_OR_AFTER, 'name' => 'last-x-day-after'],
                ['id' => FilterOption::DAY_NEXT_X_DAY_OR_AFTER, 'name' => 'next-x-day-after'],
                ['id' => FilterOption::DAY_LAST_X_DAY_OR_BEFORE, 'name' => 'last-x-day-or-before'],
                ['id' => FilterOption::DAY_NEXT_X_DAY_OR_BEFORE, 'name' => 'next-x-day-or-before'],
                
                ['id' => FilterOption::DAY_NOT_NULL, 'name' => 'not-null'],
                ['id' => FilterOption::DAY_NULL, 'name' => 'null'],
            ],
            FilterType::USER => [
                ['id' => FilterOption::USER_EQ_USER, 'name' => 'eq-user'],
                ['id' => FilterOption::USER_NE_USER, 'name' => 'ne-user'],
                ['id' => FilterOption::USER_EQ, 'name' => 'eq'],
                ['id' => FilterOption::USER_NE, 'name' => 'ne'],
                ['id' => FilterOption::USER_NOT_NULL, 'name' => 'not-null'],
                ['id' => FilterOption::USER_NULL, 'name' => 'null'],
            ],
            FilterType::WORKFLOW => [
                ['id' => FilterOption::EQ, 'name' => 'eq'],
                ['id' => FilterOption::NE, 'name' => 'ne'],
            ],
        ];
    }
}
