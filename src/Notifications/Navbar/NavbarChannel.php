<?php

namespace Exceedone\Exment\Notifications\Navbar;

use Illuminate\Notifications\Notification;

class NavbarChannel
{
    /**
     * Notify
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        $notification->toNavbar($notifiable);
    }
}
