<?php

namespace Exceedone\Exment\Model;

/**
 * Feature 1: per-user state of un-actioned workflow tasks.
 * One row = this user has seen the task of this record; with hidden_flg, they have also taken
 * it off their list (the "delete" of the task list - the record itself is never touched).
 *
 * @property mixed $target_user_id
 * @property mixed $task_key
 * @property mixed $hidden_flg
 */
class WorkflowTaskRead extends ModelBase
{
    protected $guarded = ['id'];

    protected static function boot()
    {
        parent::boot();

        // Defence in depth, same as NotifyNavbar: the "seen" state is strictly personal.
        // The service already filters on target_user_id, but a future query that forgets to
        // would otherwise expose (or overwrite) another user's state.
        static::addGlobalScope('target_user', function ($builder) {
            return $builder->where('target_user_id', \Exment::getUserId());
        });
    }
}
