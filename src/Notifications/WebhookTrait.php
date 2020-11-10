<?php

namespace Exceedone\Exment\Notifications;

trait WebhookTrait
{
    protected $subject;
    protected $body;
    protected $webhook_url;

    /**
     * Get the value of the notifiable's primary key.
     *
     * @return String
     */
    public function getKey()
    {
        return $this->webhook_url;
    }

    /**
     * Get the webhook url.
     *
     * @return String
     */
    public function getWebhookUrl()
    {
        return $this->webhook_url;
    }

    /**
     * Get the subject of message.
     *
     * @return String
     */
    public function getSubject()
    {
        return $this->subject;
    }

    /**
     * Get the body of message.
     *
     * @return String
     */
    public function getBody()
    {
        return $this->body;
    }
}
