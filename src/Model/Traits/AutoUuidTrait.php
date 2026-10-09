<?php

namespace Exceedone\Exment\Model\Traits;

/**
 * set uuid automatic
 *
 * @method static \Illuminate\Support\Collection allRecords(\Closure $filter = null, $isGetAll = true, $with = [])
 * @method static \Illuminate\Support\Collection allRecordsCache(\Closure $filter = null, $isGetAll = true, $with = [])
 */
trait AutoUuidTrait
{
    use AutoUuidTraitBase;


    // @phpstan-ignore-next-line
    protected static $uuid_key = 'uuid';


    // @phpstan-ignore-next-line
    public static function bootAutoUuidTrait()
    {
        self::observe(AutoUuidObserver::class);
    }
}
