<?php

namespace Exceedone\Exment\Notifications;

trait WebhookTrait
{
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
     * @return string
     */
    public function getWebhookUrl()
    {
        return $this->webhook_url;
    }
}
