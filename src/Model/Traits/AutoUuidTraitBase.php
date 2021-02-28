<?php

namespace Exceedone\Exment\Model\Traits;

/**
 * set uuid automatic
 *
 * @property static $key
 */
trait AutoUuidTraitBase
{
    /**
     * find by string uuid
     * 
     * @deprecated version
     */
    public static function findBySuuid($uuid)
    {
        return static::findByUuid($uuid);
    }

    /**
     * find by string uuid
     */
    public static function findByUuid($uuid)
    {
        if (!isset($uuid)) {
            return null;
        }
        // if exists "allRecords" class, call this
        if (method_exists(get_called_class(), "allRecords")) {
            return static::allRecords(function ($record) use ($uuid) {
                return array_get($record, static::$uuid_key) == $uuid;
            })->first();
        }
        return static::where(static::$uuid_key, $uuid)->first();
    }
}
