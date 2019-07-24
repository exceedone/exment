<?php

namespace Exceedone\Exment\Model;

class NotifyNavbar extends ModelBase
{
    protected static function boot()
    {
        // add global scope
        static::addGlobalScope('target_user', function ($builder) {
            return $builder->where('target_user_id', \Exment::user()->base_user_id)
                ->orderBy('read_flg', 'asc')->orderBy('created_at', 'desc');
        });

        // delete overflow rows after created.
        // static::created(function ($model) {
        //     $count = self::withoutGlobalScope('target_user')
        //                 ->where('target_user_id', $model->target_user_id)
        //                 ->count();
        //     $max_count = config('exment.notify_page_max', 100);

        //     if ($count > $max_count) {
        //         // delete overflow rows
        //         self::withoutGlobalScope('target_user')
        //             ->orderBy('read_flg', 'desc')
        //             ->orderBy('created_at', 'asc')
        //             ->take($count - $max_count)->delete();
        //     }
        // });
    }
}
