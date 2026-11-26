<?php

namespace Exceedone\Exment\Services\Notify;

use Carbon\Carbon;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Jobs\JsonWebhookJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Illuminate\Support\Str;

/**
 * A notification as JSON to any URL (ISS02912).
 *
 * The notification posts the record (the columns chosen in the notification, nothing else),
 * the table, who did what and when, and the subject and body of its mail template. With a
 * secret, the receiver can check that Exment sent it:
 *
 *   X-Exment-Timestamp: 1764140000
 *   X-Exment-Signature: sha256=hex(HMAC-SHA256(secret, timestamp + "." + body))
 *
 * It is sent by a job (JsonWebhookJob): after the answer of a save, or by the queue
 * (NotifyRunner), tried again when it fails, and the user who made the notification gets a bell
 * when it is not sent at last. The URL is checked as the Slack and Teams ones are
 * (WebhookUrlGuard), each time it is sent to, and the connection goes to the address checked.
 *
 * Off until a system administrator turns it on (Features::WEBHOOK_NOTIFY): the data leaves the
 * site.
 */
class JsonWebhook
{
    public const EVENT = 'exment.notify';

    /** a secret stored by Notify (encrypted) begins with this */
    public const SECRET_PREFIX = 'enc:';

    public const HEADER_TIMESTAMP = 'X-Exment-Timestamp';
    public const HEADER_SIGNATURE = 'X-Exment-Signature';
    public const HEADER_EVENT = 'X-Exment-Event';
    public const HEADER_DELIVERY = 'X-Exment-Delivery';

    /**
     * Send the notification to the URL of the action, by a job.
     *
     * @param array<string, mixed> $action_setting
     * @param array<string, mixed> $prms the parameters of the notification (saved_type: what was done)
     */
    public static function send(?Notify $notify, array $action_setting, ?CustomValue $custom_value, ?CustomTable $custom_table, array $prms, ?string $subject, ?string $body): ?JsonWebhookJob
    {
        if (!Features::enabled(Features::WEBHOOK_NOTIFY)) {
            return null;
        }
        $url = strval(array_get($action_setting, 'webhook_url'));
        if (is_nullorempty($url)) {
            return null;
        }

        $delivery = (string)Str::uuid();
        $payload = static::payload($delivery, $notify, $action_setting, $custom_value, $custom_table ?? $custom_value?->custom_table, $prms, $subject, $body);
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            \Log::warning('[Exment] a webhook was not sent, its data is not JSON (notify ' . ($notify->id ?? '') . ')');
            return null;
        }

        $secret = array_get($action_setting, 'webhook_secret');
        $job = new JsonWebhookJob(
            $url,
            $json,
            is_nullorempty($secret) ? null : strval($secret),
            isset($notify) ? intval($notify->id) : null,
            $delivery
        );
        dispatch($job);
        return $job;
    }

    /**
     * What is posted.
     *
     * @param array<string, mixed> $action_setting
     * @param array<string, mixed> $prms
     * @return array<string, mixed>
     */
    public static function payload(string $delivery, ?Notify $notify, array $action_setting, ?CustomValue $custom_value, ?CustomTable $custom_table, array $prms, ?string $subject, ?string $body): array
    {
        $trigger = isset($notify) ? NotifyTrigger::getEnum($notify->notify_trigger) : null;
        $login = \Exment::user();
        $user = isset($login) ? $login->base_user : null;

        return [
            'event' => static::EVENT,
            'id' => $delivery,
            'sent_at' => Carbon::now()->toIso8601String(),
            // time, create_update_data, button, workflow, ...
            'trigger' => isset($trigger) ? $trigger->lowerKey() : null,
            // created, updated, comment, attachmented (a save)
            'action' => isset($prms['saved_type']) ? strval($prms['saved_type']) : null,
            'notify' => isset($notify) ? [
                'id' => intval($notify->id),
                'name' => $notify->notify_view_name,
            ] : null,
            'table' => isset($custom_table) ? [
                'name' => $custom_table->table_name,
                'label' => $custom_table->table_view_name,
            ] : null,
            'record' => isset($custom_value) ? static::record($custom_value, $custom_table, $action_setting) : null,
            'user' => isset($user) ? [
                'id' => intval($user->id),
                'name' => strval($user->getLabel()),
            ] : null,
            'subject' => $subject,
            'body' => $body,
        ];
    }

    /**
     * The record: its id, label and page, and the values of the columns chosen.
     *
     * @param array<string, mixed> $action_setting
     * @return array<string, mixed>
     */
    protected static function record(CustomValue $custom_value, ?CustomTable $custom_table, array $action_setting): array
    {
        $values = [];
        $texts = [];
        foreach ((array)array_get($action_setting, 'webhook_columns', []) as $column_id) {
            $column = CustomColumn::getEloquent($column_id, $custom_table);
            if (!isset($column)) {
                continue;
            }
            $name = strval($column->column_name);
            // as stored (the id of a user, of a record of another table, ...)
            $values[$name] = array_get($custom_value->value, $name);
            // as shown
            $text = $custom_value->getValue($name, true);
            $texts[$name] = is_scalar($text) || $text instanceof \Stringable ? strval($text) : null;
        }

        return [
            'id' => intval($custom_value->id),
            'label' => strval($custom_value->getLabel()),
            'url' => strval($custom_value->getUrl()),
            'created_at' => static::time($custom_value->created_at),
            'updated_at' => static::time($custom_value->updated_at),
            'deleted' => !is_null($custom_value->deleted_at),
            'values' => (object)$values,
            'texts' => (object)$texts,
        ];
    }

    /**
     * @param mixed $time
     */
    protected static function time($time): ?string
    {
        if (is_nullorempty($time)) {
            return null;
        }
        return Carbon::parse($time)->toIso8601String();
    }

    public static function signature(string $secret, string $timestamp, string $body): string
    {
        return 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $body, $secret);
    }

    /**
     * The secret, kept encrypted in the notification.
     */
    public static function encryptSecret(?string $secret): ?string
    {
        if (is_nullorempty($secret) || str_starts_with(strval($secret), static::SECRET_PREFIX)) {
            return $secret;
        }
        return static::SECRET_PREFIX . encrypt(strval($secret));
    }

    public static function plainSecret(?string $stored): ?string
    {
        if (is_nullorempty($stored)) {
            return null;
        }
        $stored = strval($stored);
        if (!str_starts_with($stored, static::SECRET_PREFIX)) {
            return $stored;
        }
        try {
            return strval(decrypt(substr($stored, strlen(static::SECRET_PREFIX))));
        } catch (\Throwable $ex) {
            // APP_KEY changed: not signed with a wrong secret
            return null;
        }
    }

    /**
     * A webhook not sent at last: logged, and a bell to the user who made the notification (the
     * system administrators when that user is not known).
     */
    public static function failed(?int $notifyId, string $url, string $error): void
    {
        $host = strval(parse_url($url, PHP_URL_HOST));
        // the address may carry a token (as a Slack one does): only its host is told
        $error = str_replace($url, $host, $error);
        \Log::warning('[Exment] a webhook was not sent (notify ' . $notifyId . ', ' . $host . '): ' . $error);

        $notify = isset($notifyId) ? Notify::find($notifyId) : null;
        $userIds = [];
        if (isset($notify) && !is_nullorempty($notify->created_user_id)) {
            $userIds[] = intval($notify->created_user_id);
        }
        if (empty($userIds)) {
            $userIds = array_map(fn ($id) => intval($id), (array)(System::system_admin_users() ?? []));
        }

        foreach (array_unique($userIds) as $userId) {
            try {
                $bell = new NotifyNavbar();
                $bell->notify_id = $notifyId ?? 0;
                $bell->target_user_id = $userId;
                $bell->notify_subject = Str::limit(exmtrans('notify.webhook_failed_subject', $notify->notify_view_name ?? ''), 190);
                $bell->notify_body = Str::limit(exmtrans('notify.webhook_failed_body', $host, $error), 1990);
                $bell->save();
            } catch (\Throwable $ex) {
                \Log::error('[Exment] the bell of a webhook not sent was not saved: ' . $ex->getMessage());
            }
        }
    }
}
