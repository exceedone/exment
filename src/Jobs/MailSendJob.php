<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Notifications\Mail\MailChannel;
use Exceedone\Exment\Notifications\Mail\MailMessage;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Notifications\Mail\MailHistory;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class MailSendJob extends Notification implements ShouldQueue
{
    use JobTrait;

    /**
     * @var MailInfo
     */
    protected $mailInfo;

    /**
     * @var MailHistory
     */
    protected $mailHistory;

    
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
        return (new MailMessage)
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
}
