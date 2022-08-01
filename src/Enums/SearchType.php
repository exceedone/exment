<?php

namespace Exceedone\Exment\Enums;

class SearchType extends EnumBase
{
    public const SELF = 0;
    public const ONE_TO_MANY = 1;
    public const MANY_TO_MANY = 2;
    public const SELECT_TABLE = 3;


    // Only use Search service summary ----------------------------------------------------
    public const SUMMARY_ONE_TO_MANY = 51;
    public const SUMMARY_MANY_TO_MANY = 52;
    public const SUMMARY_SELECT_TABLE = 53;

    public static function isSummarySearchType($search_type)
    {
        return in_array($search_type, [static::SUMMARY_ONE_TO_MANY, static::SUMMARY_MANY_TO_MANY, static::SUMMARY_SELECT_TABLE]);
    }

    public static function isOneToMany($search_type)
    {
        return in_array($search_type, [static::ONE_TO_MANY, static::SUMMARY_ONE_TO_MANY]);
    }
    public static function isManyToMany($search_type)
    {
        return in_array($search_type, [static::MANY_TO_MANY, static::SUMMARY_MANY_TO_MANY]);
    }
    public static function isSelectTable($search_type)
    {
        return in_array($search_type, [static::SELECT_TABLE, static::SUMMARY_SELECT_TABLE]);
    }
}
