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
        // Laravel 13: Model::observe() does `new static`, which re-enters boot during a
        // boot{Trait} hook and throws (Model::bootIfNotBooted). Register the observer's
        // events directly instead — same behavior (set suuid on creating/updating).
        $observer = new AutoSUuidObserver();
        static::creating([$observer, 'creating']);
        static::updating([$observer, 'updating']);
    }
}
