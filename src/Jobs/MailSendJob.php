<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Notifications\Mail\MailChannel;
use Exceedone\Exment\Notifications\Mail\MailMessage;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Notifications\Mail\MailHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Jobs;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Enums\SystemTableName;

class MailSendJob extends Notification implements ShouldQueue
{
    use JobTrait;
    use Notifiable;

    /**
     * @var MailInfo
     */
    protected $mailInfo;

    /**
     * @var MailHistory
     */
    protected $mailHistory;
    protected $user;
    protected $finalUser;


    public function __construct($user = null, $finalUser = false)
    {
        $this->user = $user;
        $this->finalUser = $finalUser;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
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
     * Handle a job failure.
     */
    public function failed($exception)
    {
        $mail_template = CustomTable::getEloquent(SystemTableName::MAIL_TEMPLATE)
            ->getValueModel()
            ->where('value->mail_key_name', 'sendmail_error')
            ->first();
        if ($mail_template && $this->finalUser) {
            $this->notify(new Jobs\NavbarJob(
                $mail_template->getValue('mail_subject'),
                $mail_template->getValue('mail_body'),
                $this->notify_id ?? -1,
                $this->user->id,
                \Exment::getUserId() ?? null,
                $this->mailHistory->getParentId(),
                $this->mailHistory->getParentType()
            ));
        }
    }

}
