<?php

namespace Exceedone\Exment\Model\Traits;

/**
 * set Suuid automatic
 *
 * @method static \Illuminate\Support\Collection allRecords(\Closure $filter = null, $isGetAll = true, $with = [])
 * @method static \Illuminate\Support\Collection allRecordsCache(\Closure $filter = null, $isGetAll = true, $with = [])
 */
trait AutoSUuidTrait
{
    public static function bootAutoSUuidTrait()
    {
        self::observe(AutoSUuidObserver::class);
    }

    /**
     * find by string suuid
     */
    public static function findBySuuid($suuid)
    {
        if (!isset($suuid)) {
            return null;
        }
        // if exists "allRecords" class, call this
        if (method_exists(get_called_class(), "allRecords")) {
            return static::allRecords(function ($record) use ($suuid) {
                return array_get($record, 'suuid') == $suuid;
            })->first();
        }
        return static::where('suuid', $suuid)->first();
    }
}
