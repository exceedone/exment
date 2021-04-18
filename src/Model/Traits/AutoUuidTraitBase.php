<?php

namespace Exceedone\Exment\Model\Traits;

/**
 * set uuid automatic
 *
 * @property static $key
 * @method static function firstRecord(\Closure $filter = null, $isGetAll = true, $with = [])
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
        // if exists "firstRecord" class, call this
        if (method_exists(get_called_class(), "firstRecord")) {
            return static::firstRecord(function ($record) use ($uuid) {
                return array_get($record, static::$uuid_key) == $uuid;
            });
        }
        return static::where(static::$uuid_key, $uuid)->first();
    }
}
