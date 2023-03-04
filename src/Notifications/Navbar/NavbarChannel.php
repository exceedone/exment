<?php

namespace Exceedone\Exment\Notifications\Navbar;

use Exceedone\Exment\Jobs\NavbarJob;
use Illuminate\Notifications\Notification;

class NavbarChannel
{
    /**
     * Notify
     *
     * @param $notifiable
     * @param Notification $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        /** @var NavbarJob $notification */
        $notification->toNavbar($notifiable);
    }
}
