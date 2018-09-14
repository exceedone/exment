<?php

namespace Exceedone\Exment\Model;

trait AutoSUuid
{
    public static function bootAutoSUuid()
    {
        self::observe(AutoSUuidObserver::class);
    }

    /**
     * find by  string suuid
     */
    public static function findBySuuid($suuid){
        return static::where('suuid', $suuid)->first();
    }
}
