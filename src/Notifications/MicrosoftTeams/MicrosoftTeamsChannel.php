<?php

namespace Exceedone\Exment\Notifications\MicrosoftTeams;

use Exceedone\Exment\Jobs\MicrosoftTeamsJob;
use Illuminate\Notifications\Notification;
use GuzzleHttp\Client as HttpClient;

class MicrosoftTeamsChannel
{
    /**
     * The HTTP client instance.
     *
     * @var \GuzzleHttp\Client
     */
    protected $http;

    /**
     * Create a new Slack channel instance.
     *
     * @param  \GuzzleHttp\Client  $http
     * @return void
     */
    public function __construct(HttpClient $http)
    {
        $this->http = $http;
    }
    /**
     * Notify
     *
     * @param  mixed  $notifiable
     * @param  MicrosoftTeamsJob  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        if (! $url = $notifiable->routeNotificationFor('microsoft_teams', $notification)) {
            return;
        }

        $this->http->post($url, $this->buildJsonPayload(
            $notification->toChat($notifiable)
        ));
    }

    /**
     * Build up a JSON payload for the Slack webhook.
     *
     * @return array
     */
    protected function buildJsonPayload($message)
    {
        return [
            'json' => [
                'title' => $message->title,
                'text' => $message->content,
            ],
        ];
    }
}
