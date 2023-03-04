<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Database\Eloquent\ExtendedBuilder;

/**
 * @property mixed $notify_id
 * @property mixed $parent_id
 * @property mixed $parent_type
 * @property mixed $target_user_id
 * @property mixed $trigger_user_id
 * @property mixed $read_flg
 * @property mixed $notify_subject
 * @property mixed $notify_body
 * @method static ExtendedBuilder take($value)
 * @method static ExtendedBuilder whereIn($column, $values, $boolean = 'and', $not = false)
 * @method static ExtendedBuilder withoutGlobalScopes(array $scopes = null)
 * @phpstan-consistent-constructor
 */
class NotifyNavbar extends ModelBase
{
    protected static function boot()
    {
        parent::boot();

        // add global scope
        static::addGlobalScope('target_user', function ($builder) {
            return $builder->where('target_user_id', \Exment::getUserId())
                ->orderBy('read_flg', 'asc')->orderBy('created_at', 'desc');
        });
    }
}
