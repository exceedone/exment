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
    use AutoUuidTraitBase;


    // @phpstan-ignore-next-line
    protected static $uuid_key = 'suuid';


    // @phpstan-ignore-next-line
    public static function bootAutoSUuidTrait()
    {
        self::observe(AutoSUuidObserver::class);
    }
}
