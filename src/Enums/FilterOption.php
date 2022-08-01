<?php

namespace Exceedone\Exment\Enums;

class FilterOption extends EnumBase
{
    public const EQ = 1;
    public const NE = 2;
    public const NOT_NULL = 3;
    public const NULL = 4;
    public const LIKE = 5;
    public const NOT_LIKE = 6;

    public const DAY_ON = 1001;
    public const DAY_ON_OR_AFTER = 1002;
    public const DAY_ON_OR_BEFORE = 1003;
    public const DAY_NOT_NULL = 1004;
    public const DAY_NULL = 1005;
    public const DAY_TODAY = 1011;
    public const DAY_TODAY_OR_AFTER = 1012;
    public const DAY_TODAY_OR_BEFORE = 1013;
    public const DAY_YESTERDAY = 1014;
    public const DAY_TOMORROW = 1015;
    public const DAY_THIS_MONTH = 1021;
    public const DAY_LAST_MONTH = 1022;
    public const DAY_NEXT_MONTH = 1023;
    public const DAY_THIS_YEAR = 1031;
    public const DAY_LAST_YEAR = 1032;
    public const DAY_NEXT_YEAR = 1033;

    public const DAY_LAST_X_DAY_OR_AFTER = 1041;
    public const DAY_LAST_X_DAY_OR_BEFORE = 1042;
    public const DAY_NEXT_X_DAY_OR_AFTER = 1043;
    public const DAY_NEXT_X_DAY_OR_BEFORE = 1044;

    public const TIME_ON_OR_AFTER = 1052;
    public const TIME_ON_OR_BEFORE = 1053;

    public const USER_EQ = 2001;
    public const USER_NE = 2002;
    public const USER_NOT_NULL = 2003;
    public const USER_NULL = 2004;
    public const USER_EQ_USER = 2011;
    public const USER_NE_USER = 2012;

    public const NUMBER_GT = 3001;
    public const NUMBER_LT = 3002;
    public const NUMBER_GTE = 3003;
    public const NUMBER_LTE = 3004;

    public const SELECT_EXISTS = 4001;
    public const SELECT_NOT_EXISTS = 4002;

    public const COMPARE_GT = 5001;
    public const COMPARE_LT = 5002;
    public const COMPARE_GTE = 5003;
    public const COMPARE_LTE = 5004;

    public const WORKFLOW_EQ_STATUS = 6001;
    public const WORKFLOW_NE_STATUS = 6002;
    public const WORKFLOW_EQ_WORK_USER = 6003;

    public static function VALUE_TYPE($filter_option)
    {
        switch ($filter_option) {
            case static::DAY_ON:
            case static::DAY_ON_OR_AFTER:
            case static::DAY_ON_OR_BEFORE:
                return FilterType::DAY;
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
            case static::USER_EQ:
            case static::USER_NE:
            case static::WORKFLOW_EQ_STATUS:
            case static::WORKFLOW_NE_STATUS:
                return FilterType::SELECT;
                // "none" is not showing condition value options
            default:
                return 'none';
        }
    }


    /**
     * Get option for condition.
     * For use view.
     *
     * @return array
     */
    public static function FILTER_OPTIONS()
    {
        $options = [
            FilterType::DEFAULT => [
                static::EQ,
                static::NE,
                static::LIKE,
                static::NOT_LIKE,
                static::NOT_NULL,
                static::NULL,
            ],
            FilterType::NUMBER => [
                static::EQ,
                static::NE,
                static::NUMBER_GT,
                static::NUMBER_LT,
                static::NUMBER_GTE,
                static::NUMBER_LTE,
                static::NOT_NULL,
                static::NULL,
            ],
            FilterType::SELECT => [
                static::SELECT_EXISTS,
                static::SELECT_NOT_EXISTS,
                static::NOT_NULL,
                static::NULL,
            ],
            FilterType::FILE => [
                static::NOT_NULL,
                static::NULL,
            ],
            FilterType::YESNO => [
                static::EQ,
            ],
            FilterType::DAY => [
                static::DAY_ON,
                static::DAY_ON_OR_AFTER,
                static::DAY_ON_OR_BEFORE,
                static::DAY_TODAY,
                static::DAY_TODAY_OR_AFTER,
                static::DAY_TODAY_OR_BEFORE,
                static::DAY_YESTERDAY,
                static::DAY_TOMORROW,
                static::DAY_THIS_MONTH,
                static::DAY_LAST_MONTH,
                static::DAY_NEXT_MONTH,
                static::DAY_THIS_YEAR,
                static::DAY_LAST_YEAR,
                static::DAY_NEXT_YEAR,

                static::DAY_LAST_X_DAY_OR_AFTER,
                static::DAY_NEXT_X_DAY_OR_AFTER,
                static::DAY_LAST_X_DAY_OR_BEFORE,
                static::DAY_NEXT_X_DAY_OR_BEFORE,

                static::DAY_NOT_NULL,
                static::DAY_NULL,
            ],
            FilterType::USER => [
                static::USER_EQ_USER,
                static::USER_NE_USER,
                static::USER_EQ,
                static::USER_NE,
                static::USER_NOT_NULL,
                static::USER_NULL,

            ],
            FilterType::WORKFLOW => [
                static::WORKFLOW_EQ_STATUS,
                static::WORKFLOW_NE_STATUS,
            ],
            FilterType::WORKFLOW_WORK_USER => [
                static::WORKFLOW_EQ_WORK_USER,
            ],
            FilterType::CONDITION => [
                // Modify to select exists
                // static::EQ,
                // static::NE,
                static::SELECT_EXISTS,
                static::SELECT_NOT_EXISTS,
            ],
            FilterType::COMPARE => [
                static::EQ,
                static::NE,
                static::COMPARE_GT,
                static::COMPARE_LT,
                static::COMPARE_GTE,
                static::COMPARE_LTE,
            ],
        ];

        return collect($options)->mapWithKeys(function ($keys, $fiterType) {
            return [$fiterType => collect($keys)->map(function ($key) {
                return ['id' => $key, 'name' => static::getTransName($key)];
            })->toArray()];
        })->toArray();
    }


    protected static function getTransName($key)
    {
        switch ($key) {
            case static::COMPARE_GT: return 'gt';
            case static::COMPARE_GTE: return 'gte';
            case static::COMPARE_LT: return 'lt';
            case static::COMPARE_LTE: return 'lte';
            case static::DAY_LAST_MONTH: return 'last-month';
            case static::DAY_LAST_X_DAY_OR_AFTER: return 'last-x-day-after';
            case static::DAY_LAST_X_DAY_OR_BEFORE: return 'last-x-day-or-before';
            case static::DAY_LAST_YEAR: return 'last-year';
            case static::DAY_NEXT_MONTH: return 'next-month';
            case static::DAY_NEXT_X_DAY_OR_AFTER: return 'next-x-day-after';
            case static::DAY_NEXT_X_DAY_OR_BEFORE: return 'next-x-day-or-before';
            case static::DAY_NEXT_YEAR: return 'next-year';
            case static::DAY_NOT_NULL: return 'not-null';
            case static::DAY_NULL: return 'null';
            case static::DAY_ON: return 'on';
            case static::DAY_ON_OR_AFTER: return 'on-or-after';
            case static::DAY_ON_OR_BEFORE: return 'on-or-before';
            case static::DAY_THIS_MONTH: return 'this-month';
            case static::DAY_THIS_YEAR: return 'this-year';
            case static::DAY_TODAY: return 'today';
            case static::DAY_TODAY_OR_AFTER: return 'today-or-after';
            case static::DAY_TODAY_OR_BEFORE: return 'today-or-before';
            case static::DAY_TOMORROW: return 'tomorrow';
            case static::DAY_YESTERDAY: return 'yesterday';
            case static::EQ: return 'eq';
            case static::LIKE: return 'like';
            case static::NE: return 'ne';
            case static::NOT_LIKE: return 'not-like';
            case static::NOT_NULL: return 'not-null';
            case static::NULL: return 'null';
            case static::NUMBER_GT: return 'gt';
            case static::NUMBER_GTE: return 'gte';
            case static::NUMBER_LT: return 'lt';
            case static::NUMBER_LTE: return 'lte';
            case static::SELECT_EXISTS: return 'select-eq';
            case static::SELECT_NOT_EXISTS: return 'select-ne';
            case static::USER_EQ: return 'select-eq';
            case static::USER_EQ_USER: return 'eq-user';
            case static::USER_NE: return 'select-ne';
            case static::USER_NE_USER: return 'ne-user';
            case static::USER_NOT_NULL: return 'not-null';
            case static::USER_NULL: return 'null';
            case static::WORKFLOW_EQ_STATUS: return 'select-eq';
            case static::WORKFLOW_NE_STATUS: return 'select-ne';
            case static::WORKFLOW_EQ_WORK_USER: return 'eq-user';
        }
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
                //case static::USER_EQ_USER:
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

        $transName = static::getTransName($condition_key);
        foreach (['condition.condition_key_options', 'custom_view.filter_condition_options'] as $key) {
            if (\Lang::has("exment::exment.$key.$transName")) {
                return \Lang::get("exment::exment.$key.$transName");
            }
        }

        return null;
    }
}
