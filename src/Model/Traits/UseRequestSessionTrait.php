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
        $key = sprintf(Define::SYSTEM_KEY_SESSION_ALL_RECORDS, self::getTableName());
        // get from request session
        $records = System::requestSession($key, function () {
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
        System::requestSession($key, $records);

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
