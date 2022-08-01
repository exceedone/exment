<?php

namespace Exceedone\Exment\Notifications;

abstract class SenderBase
{
    protected $subject;
    protected $body;

    /**
     * Get the subject of message.
     *
     * @return string
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Get the body of message.
     *
     * @return string
     */
    public function getBody()
    {
        return $this->body;
    }

    /**
     * Send notify
     *
     * @return void
     */
    abstract public function send();
}
