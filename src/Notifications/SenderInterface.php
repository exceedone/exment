<?php

namespace Exceedone\Exment\Notifications;

interface SenderInterface
{
    /**
     * Send notify
     *
     * @return void
     */
    public function send();
}
