<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Jobs;

class MicrosoftTeamsSender extends SenderBase
{
    use Notifiable;
    use WebhookTrait;

    /**
     * Create a new notification instance.
     *
     * @param mixed $webhook_url
     * @param mixed $subject
     * @param mixed $body
     * @return void
     */
    public function __construct($webhook_url, $subject, $body)
    {
        $this->subject = $subject;
        $this->body = $body;
        $this->webhook_url = $webhook_url;
    }


    /**
     * Initialize $this
     *
     * @param mixed $webhook_url
     * @param mixed $subject
     * @param mixed $body
     * @param array<string, mixed> $options
     * @return MicrosoftTeamsSender
     */
    public static function make($webhook_url, $subject, $body, array $options = []): MicrosoftTeamsSender
    {
        return new self($webhook_url, $subject, $body);
    }


    /**
     * @return string|null
     */
    protected function routeNotificationForMicrosoftTeams()
    {
        return $this->webhook_url;
    }

    /**
     * Send notify
     *
     * @return void
     */
    public function send()
    {
        // replace word
        $teams_content = $this->editContent();
        // send slack message
        $this->notify(new Jobs\MicrosoftTeamsJob($this->subject, $teams_content));
    }

    /**
     * replace url to slack format.
     *
     * @return string
     */
    protected function editContent()
    {
        $content = $this->body;
        preg_match_all(Define::RULES_REGEX_LINK_FORMAT, $content, $matches);

        // @phpstan-ignore-next-line
        if (isset($matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $match = $matches[1][$i];
                $matchString = $matches[0][$i];
                $matchName = $matches[2][$i];
                $str = "[$matchName]($match)";
                $content = str_replace($matchString, $str, $content);
            }
        }

        // replace <br />
        return replaceBreak($content, false);
    }
}
