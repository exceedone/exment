<?php

namespace Exceedone\Exment\Services;

class QueryLogger
{
    protected static $queries = [];

    public static function add($query)
    {
        $index = count(self::$queries) + 1;
        self::$queries[] = "{$index}. {$query}";
    }

    public static function all()
    {
        return static::$queries;
    }

    public static function clear()
    {
        static::$queries = [];
    }
}
