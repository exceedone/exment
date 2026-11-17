<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Closure;

trait UseRequestSessionTrait
{
    /**
     * get all records. use system session
     * @param Closure|null $filter
     * @param bool $isGetAll
     * @param array<int|string, mixed> $with
     * @return \Illuminate\Support\Collection<int, static>|static|null
     */
    public static function allRecords(Closure $filter = null, $isGetAll = true, $with = [])
    {
        return static::_allRecords('requestSession', $filter, $isGetAll, $with);
    }

    /**
     * get all records. use cache
     * @param Closure|null $filter
     * @param bool $isGetAll
     * @param array<int|string, mixed> $with
     * @return \Illuminate\Support\Collection<int, static>|static|null
     */
    public static function allRecordsCache(Closure $filter = null, $isGetAll = true, $with = [])
    {
        return static::_allRecords('cache', $filter, $isGetAll, $with);
    }

    /**
     * get first record. use system session
     * @param Closure|null $filter
     * @param bool $isGetAll
     * @param array<int|string, mixed> $with
     * @return static|null
     */
    public static function firstRecord(Closure $filter = null, $isGetAll = true, $with = [])
    {

        // @phpstan-ignore-next-line
        return static::_allRecords('requestSession', $filter, $isGetAll, $with, true);
    }

    /**
     * get first record. use cache
     * @param Closure|null $filter
     * @param bool $isGetAll
     * @param array<int|string, mixed> $with
     * @return static|null
     */
    public static function firstRecordCache(Closure $filter = null, $isGetAll = true, $with = [])
    {

        // @phpstan-ignore-next-line
        return static::_allRecords('cache', $filter, $isGetAll, $with, true);
    }

    /**
     * get children like hasMany. use cache
     * @template TChild
     * @param class-string<TChild> $className a model using this trait
     * @param string $keyName
     * @param string $idName
     * @return \Illuminate\Support\Collection<int, TChild>
     */
    public function hasManyCache($className, $keyName, $idName = 'id')
    {
        $id = $this->{$idName};
        if (is_null($id)) {
            return $className::allRecordsCache(function ($record) use ($keyName, $id) {
                return $record->{$keyName} == $id;
            }, false);
        }
        return $className::recordsCacheBy([$keyName], [$id]);
    }

    /**
     * Index of the cached records, by the values of some fields.
     * key: class name and field names. value: [the records collection the index was made from, [index key => [original key => record]]]
     *
     * @var array<string, array{0: mixed, 1: array<string, array<int|string, mixed>>}>
     */
    protected static $recordIndexes = [];

    /**
     * Get the records (from the cache) whose fields equal the values, like allRecordsCache() with a filter,
     * but with an index instead of checking every record each time.
     * The index is made again when the cached records are replaced.
     *
     * @param array<string> $fields
     * @param array<mixed> $values
     * @return \Illuminate\Support\Collection<int, static>
     */
    public static function recordsCacheBy(array $fields, array $values)
    {
        return static::_recordsBy('cache', $fields, $values);
    }

    /**
     * Same as recordsCacheBy(), but from the request session, like allRecords().
     *
     * @param array<string> $fields
     * @param array<mixed> $values
     * @return \Illuminate\Support\Collection<int, static>
     */
    public static function recordsBy(array $fields, array $values)
    {
        return static::_recordsBy('requestSession', $fields, $values);
    }

    /**
     * @param string $func 'cache' or 'requestSession'
     * @param array<string> $fields
     * @param array<mixed> $values
     * @return \Illuminate\Support\Collection<int, static>
     */
    protected static function _recordsBy(string $func, array $fields, array $values)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        $getRecords = function () {
            return self::with([])->get();
        };
        $records = $func === 'cache'
            ? System::cache($key, $getRecords)
            : System::requestSession($key, $getRecords);

        $indexKey = static::class . '|' . implode('|', $fields);
        if (!isset(static::$recordIndexes[$indexKey]) || static::$recordIndexes[$indexKey][0] !== $records) {
            $index = [];
            foreach ($records as $originalKey => $record) {
                $index[static::recordIndexValue($record, $fields)][$originalKey] = $record;
            }
            static::$recordIndexes[$indexKey] = [$records, $index];
        }

        $valueKey = static::recordIndexValue(null, $fields, $values);
        return collect(static::$recordIndexes[$indexKey][1][$valueKey] ?? []);
    }

    /**
     * Get the first record (from the cache) whose fields equal the values.
     * If not found, read all records from the database again, like firstRecordCache().
     *
     * @param array<string> $fields
     * @param array<mixed> $values
     * @return static|null
     */
    public static function firstRecordCacheBy(array $fields, array $values)
    {
        $record = static::recordsCacheBy($fields, $values)->first();
        if (isset($record)) {
            return $record;
        }

        // not found: same as firstRecordCache(), which reads all records again (e.g. a record made in this request)
        return static::firstRecordCache(function ($record) use ($fields, $values) {
            foreach ($fields as $i => $field) {
                if (array_get($record, $field) != $values[$i]) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Same as firstRecordCacheBy(), but from the request session, like firstRecord().
     *
     * @param array<string> $fields
     * @param array<mixed> $values
     * @return static|null
     */
    public static function firstRecordBy(array $fields, array $values)
    {
        $record = static::recordsBy($fields, $values)->first();
        if (isset($record)) {
            return $record;
        }

        // @phpstan-ignore-next-line
        return static::firstRecord(function ($record) use ($fields, $values) {
            foreach ($fields as $i => $field) {
                if (array_get($record, $field) != $values[$i]) {
                    return false;
                }
            }
            return true;
        });
    }

    /**
     * Make the index key of a record or of the values.
     *
     * @param mixed $record
     * @param array<string> $fields
     * @param array<mixed>|null $values
     * @return string
     */
    protected static function recordIndexValue($record, array $fields, ?array $values = null): string
    {
        $keys = [];
        foreach ($fields as $i => $field) {
            $value = isset($values) ? ($values[$i] ?? null) : array_get($record, $field);
            if (is_bool($value)) {
                $value = (int)$value;
            }
            $keys[] = is_scalar($value) ? (string)$value : '';
        }
        return implode("\0", $keys);
    }

    /**
     * get all records.
     * @param string $func
     * @param Closure|null $filter
     * @param bool $isGetAll
     * @param array<int|string, mixed> $with
     * @param bool $first
     * @return \Illuminate\Support\Collection<int, static>|static|null
     */
    protected static function _allRecords($func, Closure $filter = null, $isGetAll = true, $with = [], $first = false)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        // get from request session
        $getRecords = function () use ($with) {
            return self::with($with)->get();
        };
        $records = $func === 'cache'
            ? System::cache($key, $getRecords)
            : System::requestSession($key, $getRecords);

        $collectFunc = $first ? 'first' : 'filter';

        // execute filter
        if (isset($filter)) {
            /** @phpstan-ignore-next-line Dynamic method call on Collection */
            $records = $records->{$collectFunc}(function ($record) use ($filter) {
                return $filter($record);
            });
        }

        if ($first) {
            if (isset($records)) {
                return $records;
            }
        } else {
            // if exists, return
            if (count($records) > 0) {
                return $records;
            }
        }

        if ((!isset($records) || count($records) == 0) && !$isGetAll) {
            /** @var \Illuminate\Support\Collection<int, static>|null $records */
            return $records;
        }

        // else, get all again
        $records = self::with($with)->get();
        if ($func === 'cache') {
            System::cache($key, $records);
        } else {
            System::requestSession($key, $records);
        }

        if (is_nullorempty($records)) {

            // @phpstan-ignore-next-line
            return $first ? null : $records;
        }

        // execute filter
        if (isset($filter)) {
            $records = $records->filter(function ($record) use ($filter) {
                return $filter($record);
            });
        }

        if ($first) {

            // @phpstan-ignore-next-line
            return $records->first();
        } else {

            // @phpstan-ignore-next-line
            return $records;
        }
    }
}
