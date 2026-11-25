<?php

namespace Exceedone\Exment\Services;

/**
 * The SQL of the current request, for the system performance log.
 * The SQL is kept with "?" (the values are not put in), so no personal or secret data is kept.
 */
class QueryLogger
{
    /** @var array<int, string> */
    protected static $queries = [];

    /** @var int */
    protected static $count = 0;

    /** @var float total time of the SQL (ms) */
    protected static $totalTime = 0.0;

    /** @var float the longest time of one SQL (ms) */
    protected static $maxTime = 0.0;

    /**
     * Keep at most this number of SQL texts in a request (the count and the time are always added up).
     */
    public const MAX_QUERIES = 500;

    /**
     * @param string $query the SQL with "?" (or a line of text)
     * @param float|null $time the time (ms)
     * @return void
     */
    public static function add($query, $time = null)
    {
        static::$count++;
        if (!is_null($time)) {
            static::$totalTime += (float)$time;
            static::$maxTime = max(static::$maxTime, (float)$time);
        }

        if (count(static::$queries) >= static::MAX_QUERIES) {
            return;
        }
        $index = static::$count;
        static::$queries[] = is_null($time) ? "{$index}. {$query}" : "{$index}. [{$time}ms] {$query}";
    }

    /**
     * @return array<int, string>
     */
    public static function all()
    {
        return static::$queries;
    }

    /**
     * @return int the number of SQL
     */
    public static function count(): int
    {
        return static::$count;
    }

    /**
     * @return float the total time of the SQL (ms)
     */
    public static function totalTime(): float
    {
        return static::$totalTime;
    }

    /**
     * @return float the longest time of one SQL (ms)
     */
    public static function maxTime(): float
    {
        return static::$maxTime;
    }

    /**
     * @return void
     */
    public static function clear()
    {
        static::$queries = [];
        static::$count = 0;
        static::$totalTime = 0.0;
        static::$maxTime = 0.0;
    }
}
