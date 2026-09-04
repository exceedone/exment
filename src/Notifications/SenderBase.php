<?php

namespace Exceedone\Exment\Notifications;

abstract class SenderBase
{
    /**
     * @var mixed
     */
    protected $subject;

    /**
     * @var mixed
     */
    protected $body;

    /**
     * Get the subject of message.
     *
     * @return mixed
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Get the body of message.
     *
     * @return mixed
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
