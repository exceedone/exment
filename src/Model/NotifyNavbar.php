<?php

namespace Exceedone\Exment\Model;

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
