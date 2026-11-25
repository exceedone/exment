<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Notifications\Mail\MailChannel;
use Exceedone\Exment\Notifications\Mail\MailMessage;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Notifications\Mail\MailHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Jobs;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;

class MailSendJob extends Notification implements ShouldQueue
{
    use JobTrait;
    use Notifiable;

    /**
     * A notice of failed mails at most once in this time for each user (ISS00161)
     */
    public const NOTICE_INTERVAL_SECONDS = 600;
    public const NOTICE_CACHE_PREFIX = 'exment_mail_failed_notice_';

    /**
     * The length of the reason kept in the send log
     */
    public const ERROR_REASON_LENGTH = 250;

    /**
     * @var MailInfo
     */
    protected $mailInfo;

    /**
     * @var MailHistory
     */
    protected $mailHistory;
    // @phpstan-ignore-next-line
    protected $userId;
    // @phpstan-ignore-next-line
    protected $finalUser;


    // @phpstan-ignore-next-line
    public function __construct($userId = null, $finalUser = false)
    {
        $this->userId = $userId;
        $this->finalUser = $finalUser;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    // @phpstan-ignore-next-line
    public function via($notifiable)
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage())
            ->setMailInfo($this->mailInfo)
            ->setMailHistory($this->mailHistory);
    }

    /**
     * Set the value of mailInfo
     *
     * @param  MailInfo  $mailInfo
     *
     * @return  self
     */
    public function setMailInfo(MailInfo $mailInfo)
    {
        $this->mailInfo = $mailInfo;

        return $this;
    }

    /**
     * Set the value of mailHistory
     *
     * @param  MailHistory  $mailHistory
     *
     * @return  self
     */
    public function setMailHistory(MailHistory $mailHistory)
    {
        $this->mailHistory = $mailHistory;

        return $this;
    }

    /**
     * Wait a little between the tries when a queue worker sends the mail (ISS00161): a mail
     * server down for a moment gets the mail later instead of three tries at once.
     *
     * @return array<int, int> seconds before the 2nd and the 3rd try
     */
    public function backoff()
    {
        return [60, 300];
    }

    /**
     * Handle a job failure: after the last try (at once on the sync connection).
     * The send log keeps a row "failed" with the reason, and the system administrators and the
     * user who sent the mail get a notice (ISS00161).
     */
    // @phpstan-ignore-next-line
    public function failed($exception)
    {
        try {
            $this->saveFailedHistory($exception);
        } catch (\Throwable $e) {
            \Log::error($e);
        }

        try {
            $this->notifyFailure();
        } catch (\Throwable $e) {
            \Log::error($e);
        }
    }

    /**
     * @param mixed $exception
     * @return void
     */
    protected function saveFailedHistory($exception)
    {
        // a job made without the mail (should not happen): nothing to log
        /** @var MailInfo|null $mailInfo */
        $mailInfo = $this->mailInfo;
        /** @var MailHistory|null $mailHistory */
        $mailHistory = $this->mailHistory;
        if (is_null($mailInfo) || is_null($mailHistory)) {
            return;
        }
        (new MailChannel())->saveHistory($this->toMail(null), MailChannel::RESULT_FAILED, static::errorReason($exception));
    }

    /**
     * A short reason of the failure for the send log.
     *
     * @param mixed $exception
     * @return string
     */
    public static function errorReason($exception): string
    {
        if (!($exception instanceof \Throwable)) {
            return '';
        }
        $message = trim(preg_replace('/\s+/u', ' ', $exception->getMessage()) ?? '');
        $text = class_basename($exception) . ($message === '' ? '' : ': ' . $message);
        return mb_strimwidth($text, 0, static::ERROR_REASON_LENGTH, '...');
    }

    /**
     * The users told about the failure: the system administrators and the user who sent the mail.
     *
     * @return array<int, string>
     */
    public function failureNoticeTargets(): array
    {
        return collect(System::system_admin_users())
            ->push($this->userId)
            ->filter(function ($id) {
                return !is_nullorempty($id);
            })
            ->map(function ($id) {
                return strval($id);
            })
            ->unique()
            ->values()
            ->all();
    }

    /**
     * A notice on the bell, at most one per user in NOTICE_INTERVAL_SECONDS: a mail server down
     * does not make a notice for each mail.
     *
     * @return void
     */
    protected function notifyFailure()
    {
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', MailKeyName::SENDMAIL_ERROR)
            ->first();
        // the template "sendmail_error"; without it (a deleted one) the text of Exment
        $subject = $mail_template ? $mail_template->getValue('mail_subject') : null;
        $body = $mail_template ? $mail_template->getValue('mail_body') : null;
        if (is_nullorempty($subject)) {
            $subject = exmtrans('mail_template.send_failed_subject');
        }
        if (is_nullorempty($body)) {
            $body = exmtrans('mail_template.send_failed_body');
        }

        /** @var MailHistory|null $mailHistory */
        $mailHistory = $this->mailHistory;
        foreach ($this->failureNoticeTargets() as $target_user_id) {
            $key = static::NOTICE_CACHE_PREFIX . $target_user_id;
            // told already in this time (a cache that keeps nothing does not stop the notice)
            if (!Cache::add($key, 1, static::NOTICE_INTERVAL_SECONDS) && Cache::has($key)) {
                continue;
            }
            // written at once: the bell does not wait for the queue that could not send the mail
            (new Jobs\NavbarJob(
                $subject,
                $body,
                // a notice of the system, not of a notify setting (notify_id is unsigned)
                0,
                $target_user_id,
                $this->userId,
                $mailHistory ? $mailHistory->getParentId() : null,
                $mailHistory ? $mailHistory->getParentType() : null
            ))->toNavbar(null);
        }
    }
}
