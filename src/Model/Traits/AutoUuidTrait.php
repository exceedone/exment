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

    protected static $key = 'uuid';

    public static function bootAutoSUuidTrait()
    {
        self::observe(AutoUuidObserver::class);
    }
}
