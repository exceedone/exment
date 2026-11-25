<?php

namespace Exceedone\Exment\Services\Notify;

use Carbon\Carbon;
use Exceedone\Exment\Model\NotifyNavbar;

/**
 * The bells of one notification, written at once (ISS02906).
 *
 * A notification to many users made each bell through a Laravel notification (a sync queue job:
 * serialize, the job, the channel), saved it alone and moved the bell cache of the user alone:
 * about a third of a save notifying 379 users. While NotifyRunner makes a notification later,
 * the bells are kept here and written with one insert a chunk, with the same columns a save of
 * NotifyNavbar writes, and the caches of their users moved after it.
 *
 * Only the bells of the deferred notifications of a saved record (NotifyRunner::runNow): any
 * other bell is made as before.
 */
class NotifyNavbarBatch
{
    protected const CHUNK = 500;

    /** @var bool */
    protected static $collecting = false;

    /** @var array<int, array<string, mixed>> */
    protected static $rows = [];

    public static function collecting(): bool
    {
        return static::$collecting;
    }

    /**
     * Keep the bells from now on, until flush().
     */
    public static function start(): void
    {
        static::$collecting = true;
        static::$rows = [];
    }

    /**
     * A bell, as NavbarJob::toNavbar() saves it.
     *
     * @param mixed $notify_id
     * @param mixed $parent_id
     * @param mixed $parent_type
     * @param mixed $subject
     * @param mixed $body
     * @param mixed $target_user_id
     * @param mixed $trigger_user_id
     */
    public static function add($notify_id, $parent_id, $parent_type, $subject, $body, $target_user_id, $trigger_user_id): void
    {
        $notify_navbar = new NotifyNavbar();
        $notify_navbar->notify_id = $notify_id ?? -1;
        $notify_navbar->parent_id = $parent_id;
        $notify_navbar->parent_type = $parent_type;
        $notify_navbar->notify_subject = $subject;
        $notify_navbar->notify_body = $body;
        $notify_navbar->target_user_id = $target_user_id;
        $notify_navbar->trigger_user_id = $trigger_user_id;

        // what the save sets (ModelBase: the user; Eloquent: the times)
        $user_id = \Exment::getUserId();
        if ($notify_navbar->saving_users && !is_nullorempty($user_id)) {
            $notify_navbar->created_user_id = $user_id;
            $notify_navbar->updated_user_id = $user_id;
        }
        if ($notify_navbar->usesTimestamps()) {
            $now = Carbon::now();
            $notify_navbar->setCreatedAt($now);
            $notify_navbar->setUpdatedAt($now);
        }

        static::$rows[] = $notify_navbar->getAttributes();
    }

    /**
     * Write the bells kept, and stop keeping them.
     */
    public static function flush(): void
    {
        $rows = static::$rows;
        static::$collecting = false;
        static::$rows = [];
        if (count($rows) === 0) {
            return;
        }

        foreach (array_chunk($rows, static::CHUNK) as $chunk) {
            NotifyNavbar::withoutGlobalScopes()->insert($chunk);
        }
        // the bell of each user answers fresh on their next poll, as a save of each did
        NotifyNavbar::navbarCacheForget(array_column($rows, 'target_user_id'));
    }
}
