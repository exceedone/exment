<?php

namespace Exceedone\Exment\Enums;

class SearchType extends EnumBase
{
    const SELF = 0;
    const ONE_TO_MANY = 1;
    const MANY_TO_MANY = 2;
    const SELECT_TABLE = 3;


    // Only use Search service summary ----------------------------------------------------
    const SUMMARY_ONE_TO_MANY = 51;
    const SUMMARY_MANY_TO_MANY = 52;
    const SUMMARY_SELECT_TABLE = 53;

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
