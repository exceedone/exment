<?php

namespace Exceedone\Exment\Services;

class QueryLogger
{
    /** @var array<int, string> */
    protected static $queries = [];

    /**
     * @param string $query
     * @return void
     */
    public static function add($query)
    {
        $index = count(self::$queries) + 1;
        self::$queries[] = "{$index}. {$query}";
    }

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return static::$queries;
    }

    /**
     * @return void
     */
    public static function clear()
    {
        static::$queries = [];
    }
}
