<?php

namespace Exceedone\Exment\Services\LineWorks;

use Exceedone\Exment\Jobs\LineWorksSendJob;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\TeamsWorkflowSender;
use Exceedone\Exment\Services\Features;
use Illuminate\Support\Str;

/**
 * The notifications to LINE WORKS (ISS00114): a message of the bot of the app to a channel
 * (NotifyAction::LINE_WORKS, its id in the notification) or to each person of the notification
 * (NotifyAction::LINE_WORKS_PERSONAL: the LINE WORKS id of the user, its mail address by default).
 *
 * The app is a system setting (client id and secret, service account, private key, bot id):
 * the secrets kept encrypted, never shown again, never written to a log. Sent by a job
 * (LineWorksSendJob): after the answer of a save or by the queue (NotifyRunner), tried again when
 * it fails, and a bell to the user who made the notification when it is not sent at last.
 *
 * Off until a system administrator turns it on (Features::LINE_WORKS_NOTIFY): the data leaves the
 * site. Built on the LINE WORKS API 2.0 as documented; tested with HTTP fakes.
 */
class LineWorks
{
    /** The text of a message at most (LINE WORKS: 2000 characters). */
    public const MAX_TEXT = 2000;

    /**
     * The settings of the app, null while one is missing.
     *
     * @return array{client_id: string, client_secret: string, service_account: string, private_key: string, bot_id: string}|null
     */
    public static function credentials(): ?array
    {
        $credentials = [
            'client_id' => trim(strval(System::system_lineworks_client_id())),
            'client_secret' => trim(strval(System::system_lineworks_client_secret())),
            'service_account' => trim(strval(System::system_lineworks_service_account())),
            'private_key' => trim(strval(System::system_lineworks_private_key())),
            'bot_id' => trim(strval(System::system_lineworks_bot_id())),
        ];
        foreach ($credentials as $value) {
            if ($value === '') {
                return null;
            }
        }
        return $credentials;
    }

    public static function enabled(): bool
    {
        return Features::enabled(Features::LINE_WORKS_NOTIFY) && static::credentials() !== null;
    }

    /**
     * The LINE WORKS id of a user: the column of the setting (its mail address by default).
     */
    public static function userIdOf(?CustomValue $user): ?string
    {
        if (!isset($user)) {
            return null;
        }
        $column = strval(System::system_lineworks_user_column() ?: 'email');
        $value = $user->getValue($column);
        if (is_nullorempty($value) && $column !== 'email') {
            $value = $user->getValue('email');
        }
        return is_nullorempty($value) ? null : trim(strval($value));
    }

    /**
     * The message: the subject, the body as text (an HTML body of a mail template loses its
     * tags), the link to the data; at most MAX_TEXT characters.
     *
     * @return array<string, mixed> the "content" of the message
     */
    public static function content(string $subject, string $body, ?string $url = null): array
    {
        $text = trim($subject);
        $paragraphs = TeamsWorkflowSender::paragraphs($body);
        if (!empty($paragraphs)) {
            $text .= "\n\n" . implode("\n\n", array_map(function ($p) {
                return str_replace("\n\n", "\n", $p);
            }, $paragraphs));
        }
        $link = is_nullorempty($url) ? '' : "\n\n" . strval($url);
        $text = Str::limit($text, static::MAX_TEXT - mb_strlen($link), '…') . $link;
        return ['type' => 'text', 'text' => $text];
    }

    /**
     * Send by a job. $to: "users/{id}" or "channels/{id}".
     *
     * @param array<string, mixed> $content
     */
    public static function send(string $to, array $content, ?Notify $notify = null): ?LineWorksSendJob
    {
        if (!static::enabled()) {
            return null;
        }
        $job = new LineWorksSendJob($to, $content, isset($notify) ? intval($notify->id) : null);
        dispatch($job);
        return $job;
    }

    public static function toUser(string $userId): string
    {
        return 'users/' . rawurlencode($userId);
    }

    public static function toChannel(string $channelId): string
    {
        return 'channels/' . rawurlencode($channelId);
    }

    public static function client(): ?LineWorksClient
    {
        $credentials = static::credentials();
        return isset($credentials) ? new LineWorksClient($credentials) : null;
    }

    /**
     * Not sent at last: logged, and a bell to the user who made the notification (the system
     * administrators when that user is not known). The person or the channel is told, not a secret.
     */
    public static function failed(?int $notifyId, string $to, string $error): void
    {
        \Log::warning('[Exment] a LINE WORKS message was not sent (notify ' . $notifyId . ', ' . $to . '): ' . $error);

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
                $bell->notify_subject = Str::limit(exmtrans('notify.lineworks_failed_subject', $notify->notify_view_name ?? ''), 190);
                $bell->notify_body = Str::limit(exmtrans('notify.lineworks_failed_body', rawurldecode($to), $error), 1990);
                $bell->save();
            } catch (\Throwable $ex) {
                \Log::error('[Exment] the bell of a LINE WORKS message not sent was not saved: ' . $ex->getMessage());
            }
        }
    }
}
