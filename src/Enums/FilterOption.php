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

    const COMPARE_GT = 5001;
    const COMPARE_LT = 5002;
    const COMPARE_GTE = 5003;
    const COMPARE_LTE = 5004;

    public static function VALUE_TYPE($filter_option)
    {
        switch ($filter_option) {
            case static::DAY_ON:
            case static::DAY_ON_OR_AFTER:
            case static::DAY_ON_OR_BEFORE:
                return FilterType::DAY;
            case static::USER_EQ:
            case static::USER_NE:
            case static::EQ:
            case static::NE:
            case static::LIKE:
            case static::NOT_LIKE:
            case static::NUMBER_GT:
            case static::NUMBER_LT:
            case static::NUMBER_GTE:
            case static::NUMBER_LTE:
                return null;
            case static::DAY_LAST_X_DAY_OR_AFTER:
            case static::DAY_LAST_X_DAY_OR_BEFORE:
            case static::DAY_NEXT_X_DAY_OR_AFTER:
            case static::DAY_NEXT_X_DAY_OR_BEFORE:
                return FilterType::NUMBER;
            case static::SELECT_EXISTS:
            case static::SELECT_NOT_EXISTS:
                return FilterType::SELECT;
            default:
                return 'none';
        }
    }

    public static function FILTER_OPTIONS()
    {
        return [
            FilterType::DEFAULT => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
                ['id' => static::LIKE, 'name' => 'like'],
                ['id' => static::NOT_LIKE, 'name' => 'not-like'],
                ['id' => static::NOT_NULL, 'name' => 'not-null'],
                ['id' => static::NULL, 'name' => 'null'],
            ],
            FilterType::NUMBER => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
                ['id' => static::NUMBER_GT, 'name' => 'gt'],
                ['id' => static::NUMBER_LT, 'name' => 'lt'],
                ['id' => static::NUMBER_GTE, 'name' => 'gte'],
                ['id' => static::NUMBER_LTE, 'name' => 'lte'],
                ['id' => static::NOT_NULL, 'name' => 'not-null'],
                ['id' => static::NULL, 'name' => 'null'],
            ],
            FilterType::SELECT => [
                ['id' => static::SELECT_EXISTS, 'name' => 'select-eq'],
                ['id' => static::SELECT_NOT_EXISTS, 'name' => 'select-ne'],
                ['id' => static::NOT_NULL, 'name' => 'not-null'],
                ['id' => static::NULL, 'name' => 'null'],
            ],
            FilterType::FILE => [
                ['id' => static::NOT_NULL, 'name' => 'not-null'],
                ['id' => static::NULL, 'name' => 'null'],
            ],
            FilterType::DAY => [
                ['id' => static::DAY_ON, 'name' => 'on'],
                ['id' => static::DAY_ON_OR_AFTER, 'name' => 'on-or-after'],
                ['id' => static::DAY_ON_OR_BEFORE, 'name' => 'on-or-before'],
                ['id' => static::DAY_TODAY, 'name' => 'today'],
                ['id' => static::DAY_TODAY_OR_AFTER, 'name' => 'today-or-after'],
                ['id' => static::DAY_TODAY_OR_BEFORE, 'name' => 'today-or-before'],
                ['id' => static::DAY_YESTERDAY, 'name' => 'yesterday'],
                ['id' => static::DAY_TOMORROW, 'name' => 'tomorrow'],
                ['id' => static::DAY_THIS_MONTH, 'name' => 'this-month'],
                ['id' => static::DAY_LAST_MONTH, 'name' => 'last-month'],
                ['id' => static::DAY_NEXT_MONTH, 'name' => 'next-month'],
                ['id' => static::DAY_THIS_YEAR, 'name' => 'this-year'],
                ['id' => static::DAY_LAST_YEAR, 'name' => 'last-year'],
                ['id' => static::DAY_NEXT_YEAR, 'name' => 'next-year'],
                
                ['id' => static::DAY_LAST_X_DAY_OR_AFTER, 'name' => 'last-x-day-after'],
                ['id' => static::DAY_NEXT_X_DAY_OR_AFTER, 'name' => 'next-x-day-after'],
                ['id' => static::DAY_LAST_X_DAY_OR_BEFORE, 'name' => 'last-x-day-or-before'],
                ['id' => static::DAY_NEXT_X_DAY_OR_BEFORE, 'name' => 'next-x-day-or-before'],
                
                ['id' => static::DAY_NOT_NULL, 'name' => 'not-null'],
                ['id' => static::DAY_NULL, 'name' => 'null'],
            ],
            FilterType::USER => [
                ['id' => static::USER_EQ_USER, 'name' => 'eq-user'],
                ['id' => static::USER_NE_USER, 'name' => 'ne-user'],
                ['id' => static::USER_EQ, 'name' => 'eq'],
                ['id' => static::USER_NE, 'name' => 'ne'],
                ['id' => static::USER_NOT_NULL, 'name' => 'not-null'],
                ['id' => static::USER_NULL, 'name' => 'null'],
            ],
            FilterType::WORKFLOW => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
            ],
            FilterType::WORKFLOW_WORK_USER => [
                ['id' => static::USER_EQ_USER, 'name' => 'eq-user'],
                ['id' => static::USER_EQ, 'name' => 'eq'],
            ],
            FilterType::CONDITION => [
                ['id' => static::EQ, 'name' => 'eq'],
            ],
            FilterType::COMPARE => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
                ['id' => static::COMPARE_GT, 'name' => 'gt'],
                ['id' => static::COMPARE_LT, 'name' => 'lt'],
                ['id' => static::COMPARE_GTE, 'name' => 'gte'],
                ['id' => static::COMPARE_LTE, 'name' => 'lte'],
            ],
        ];
    }
    
    /**
     * Get option for condition
     *
     * @return void
     */
    public static function FILTER_CONDITION_OPTIONS()
    {
        return [
            FilterType::DEFAULT => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
            ],
            FilterType::NUMBER => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
                ['id' => static::NUMBER_GT, 'name' => 'gt'],
                ['id' => static::NUMBER_LT, 'name' => 'lt'],
                ['id' => static::NUMBER_GTE, 'name' => 'gte'],
                ['id' => static::NUMBER_LTE, 'name' => 'lte'],
            ],
            FilterType::SELECT => [
                ['id' => static::SELECT_EXISTS, 'name' => 'select-eq'],
                ['id' => static::SELECT_NOT_EXISTS, 'name' => 'select-ne'],
            ],
            FilterType::FILE => [
            ],
            FilterType::DAY => [
                ['id' => static::DAY_ON, 'name' => 'on'],
                ['id' => static::DAY_ON_OR_AFTER, 'name' => 'on-or-after'],
                ['id' => static::DAY_ON_OR_BEFORE, 'name' => 'on-or-before'],
            ],
            FilterType::USER => [
                ['id' => static::USER_EQ, 'name' => 'eq'],
                ['id' => static::USER_NE, 'name' => 'ne'],
            ],
            FilterType::WORKFLOW => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
            ],
            FilterType::WORKFLOW_WORK_USER => [
                ['id' => static::USER_EQ_USER, 'name' => 'eq-user'],
            ],
            FilterType::CONDITION => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
            ],
            FilterType::COMPARE => [
                ['id' => static::EQ, 'name' => 'eq'],
                ['id' => static::NE, 'name' => 'ne'],
                ['id' => static::COMPARE_GT, 'name' => 'gt'],
                ['id' => static::COMPARE_LT, 'name' => 'lt'],
                ['id' => static::COMPARE_GTE, 'name' => 'gte'],
                ['id' => static::COMPARE_LTE, 'name' => 'lte'],
            ],
        ];
    }
    
    public static function getCompareOptions($enum)
    {
        switch ($enum) {
            case static::USER_EQ:
            case static::SELECT_EXISTS:
                return static::EQ;
            case static::SELECT_NOT_EXISTS:
            case static::USER_NE:
                return static::NE;
        }

        return $enum;
    }
    
    /**
     * get condition key text (for form condition only)
     */
    public static function getConditionKeyText($condition_key)
    {
        $enum = $condition_key;

        switch ($condition_key) {
            case static::EQ:
            case static::USER_EQ:
            case static::SELECT_EXISTS:
            case static::DAY_ON:
            case static::USER_EQ_USER:
                return '';
            case static::NE:
            case static::SELECT_NOT_EXISTS:
            case static::USER_NE:
                $enum = static::NE;
                break;
        }

        $enum = static::getEnum($enum);
        if (!isset($enum)) {
            return null;
        }

        return $enum->transKey('condition.condition_key_options');
    }
}
