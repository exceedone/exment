<?php

namespace Exceedone\Exment\Model\Traits;

trait AutoSUuidTrait
{
    public static function bootAutoSUuidTrait()
    {
        self::observe(AutoSUuidObserver::class);
    }

    /**
     * find by string suuid
     */
    public static function findBySuuid($suuid){
        return static::where('suuid', $suuid)->first();
    }
}
