<?php

namespace Exceedone\Exment\Notifications\Mail;

use Illuminate\Notifications\Notification;

class MailChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        $notification->toMail($notifiable);
    }
}
