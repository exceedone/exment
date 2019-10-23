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
    public static function allRecords(Closure $filter = null, $isGetAll = true)
    {
        return static::_allRecords('requestSession', $filter, $isGetAll);
    }
    
    /**
     * get all records. use cache
     */
    public static function allRecordsCache(Closure $filter = null, $isGetAll = true)
    {
        return static::_allRecords('cache', $filter, $isGetAll);
    }
    
    /**
     * reset all records. use cache
     */
    public static function resetAllRecordsCache()
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        System::resetCache($key);
    }
    
    /**
     * get all records.
     */
    protected static function _allRecords($func, Closure $filter = null, $isGetAll = true)
    {
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        // get from request session
        $records = System::{$func}($key, function () {
            return self::all();
        });

        // execute filter
        if (isset($filter)) {
            $records = $records->filter(function ($record) use ($filter) {
                return $filter($record);
            });
        }

        // if exists, return
        if (count($records) > 0) {
            return $records;
        }
        
        if ((!isset($records) || count($records) == 0) && !$isGetAll) {
            return $records;
        }

        // else, get all again
        $records = self::all();
        System::{$func}($key, $records);

        if (!isset($records)) {
            return $records;
        }

        // execute filter
        if (isset($filter)) {
            $records = $records->filter(function ($record) use ($filter) {
                return $filter($record);
            });
        }
        return $records;
    }
}
