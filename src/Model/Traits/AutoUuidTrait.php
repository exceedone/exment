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
        // Laravel 13: Model::observe() does `new static`, which re-enters boot during a
        // boot{Trait} hook and throws (Model::bootIfNotBooted). Register the observer's
        // events directly instead — same behavior (set uuid on creating/updating).
        $observer = new AutoUuidObserver();
        static::creating([$observer, 'creating']);
        static::updating([$observer, 'updating']);
    }
}
