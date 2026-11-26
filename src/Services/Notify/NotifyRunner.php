<?php

namespace Exceedone\Exment\Services\Notify;

use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Jobs\NotifyUserJob;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Notify;

/**
 * When the notifications of a saved record are made (ISS02906).
 *
 * A notification to many users made them one by one while the save waited (379 users: about 2
 * seconds). A save now only leaves the notification to be made; the recipients are found and
 * told after it:
 * - queue: by the queue worker (a site with QUEUE_CONNECTION other than sync already needs one
 *   for its mails and bells)
 * - after_response: in the same PHP process after the answer is sent (QUEUE_CONNECTION=sync)
 * - sync: at once, as before (and always for a command: it has no answer to send first, and an
 *   import must not keep every record in memory)
 *
 * EXMENT_NOTIFY_MODE=queue|after_response|sync; not set (auto): queue when the queue is not
 * sync, else after_response in a request and sync in a command.
 */
class NotifyRunner
{
    public const QUEUE = 'queue';
    public const AFTER_RESPONSE = 'after_response';
    public const SYNC = 'sync';

    public static function mode(): string
    {
        $mode = strtolower(strval(config('exment.notify_mode', 'auto')));
        if (in_array($mode, [static::QUEUE, static::AFTER_RESPONSE, static::SYNC], true)) {
            return $mode;
        }

        if (strval(config('queue.default')) !== 'sync') {
            return static::QUEUE;
        }
        return app()->runningInConsole() ? static::SYNC : static::AFTER_RESPONSE;
    }

    /**
     * Whether this notification of a saved record is made later. Only what can be made the
     * same later: the record and texts (not the share, whose targets are models of the moment,
     * nor the delete, after which the record may be gone).
     *
     * @param mixed $notifySavedType
     * @param array<string, mixed> $options
     */
    public static function defers($notifySavedType, array $options): bool
    {
        if (static::mode() === static::SYNC) {
            return false;
        }
        $type = NotifySavedType::getEnum($notifySavedType);
        $value = isset($type) ? strval($type->getValue()) : strval($notifySavedType);
        if (!in_array($value, [NotifySavedType::CREATE, NotifySavedType::UPDATE, NotifySavedType::COMMENT, NotifySavedType::ATTACHMENT], true)) {
            return false;
        }
        return !isset($options['targetUserOrgs']);
    }

    /**
     * Make the notification after the response, or by the queue.
     *
     * @param array<string, mixed> $options the options of Notify::notifyUser (texts only)
     */
    public static function later(Notify $notify, CustomValue $custom_value, array $options): void
    {
        $actorId = \Exment::getUserId();

        if (static::mode() === static::QUEUE) {
            dispatch(new NotifyUserJob(
                intval($notify->id),
                strval($custom_value->custom_table->table_name),
                intval($custom_value->id),
                static::texts($options),
                is_null($actorId) ? null : intval($actorId)
            ));
            return;
        }

        // after the response: the same models, the same login user (the guard keeps it)
        app()->terminating(function () use ($notify, $custom_value, $options) {
            static::runNow($notify, $custom_value, $options);
        });
    }

    /**
     * Make the notification now; a failure is logged (nothing to show it to after the response).
     *
     * @param array<string, mixed> $options
     */
    public static function runNow(Notify $notify, CustomValue $custom_value, array $options): void
    {
        try {
            $notify->notifyUser($custom_value, $options);
        } catch (\Throwable $ex) {
            \Log::error('[Exment] a notification of a saved record failed (notify ' . $notify->id . ', ' . $custom_value->custom_table->table_name . ' ' . $custom_value->id . '): ' . $ex->getMessage(), ['exception' => $ex]);
        }
    }

    /**
     * The options that go into the queue: texts only.
     *
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    protected static function texts(array $options): array
    {
        $texts = [];
        foreach (['comment', 'attachment'] as $key) {
            if (isset($options[$key])) {
                $texts[$key] = strval($options[$key]);
            }
        }
        $prms = [];
        foreach ((array)($options['prms'] ?? []) as $key => $value) {
            if (is_scalar($value) || is_null($value)) {
                $prms[$key] = $value;
            }
        }
        $texts['prms'] = $prms;
        return $texts;
    }
}
