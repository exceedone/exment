<?php

namespace Exceedone\Exment\Model\Traits;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Define;
use Closure;

trait UseRequestSessionTrait
{
    /**
     * get all records. use system session
     */
    public static function allRecords(Closure $filter = null, $isGetAll = true, $with = [])
    {
        return static::_allRecords('requestSession', $filter, $isGetAll, $with);
    }

    /**
     * get all records. use cache
     */
    public static function allRecordsCache(Closure $filter = null, $isGetAll = true, $with = [])
    {
        return static::_allRecords('cache', $filter, $isGetAll, $with);
    }

    /**
     * get first record. use system session
     */
    public static function firstRecord(Closure $filter = null, $isGetAll = true, $with = [])
    {
        return static::_allRecords('requestSession', $filter, $isGetAll, $with, true);
    }

    /**
     * get first record. use cache
     */
    public static function firstRecordCache(Closure $filter = null, $isGetAll = true, $with = [])
    {
        return static::_allRecords('cache', $filter, $isGetAll, $with, true);
    }

    /**
     * get children like hasMany. use cache
     */
    public function hasManyCache($className, $keyName, $idName = 'id')
    {
        return $className::allRecordsCache(function ($record) use ($keyName, $idName) {
            return $record->{$keyName} == $this->{$idName};
        }, false);
    }

    /**
     * get all records.
     */
    protected static function _allRecords($func, Closure $filter = null, $isGetAll = true, $with = [], $first = false)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        // get from request session
        $records = System::{$func}($key, function () use ($with) {
            return self::with($with)->get();
        });

        $collectFunc = $first ? 'first' : 'filter';

        // execute filter
        if (isset($filter)) {
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
            return $records;
        }

        // else, get all again
        $records = self::with($with)->get();
        System::{$func}($key, $records);

        if (is_nullorempty($records)) {
            return $first ? null : $records;
        }

        // execute filter
        if (isset($filter)) {
            $records = $records->filter(function ($record) use ($filter) {
                return $filter($record);
            });
        }

        if ($first) {
            return $records->first();
        } else {
            return $records;
        }
    }
}
