<?php

namespace Exceedone\Exment\Notifications;

trait WebhookTrait
{
    /**
     * @var string|null
     */
    protected $webhook_url;

    /**
     * Get the value of the notifiable's primary key.
     *
     * @return string|null
     */
    // @phpstan-ignore-next-line
    public function getKey()
    {
        return $this->webhook_url;
    }

    /**
     * Get the webhook url.
     *
     * @return string|null
     */
    // @phpstan-ignore-next-line
    public function getWebhookUrl()
    {
        return $this->webhook_url;
    }
}
