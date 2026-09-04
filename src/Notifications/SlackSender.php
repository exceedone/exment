<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Jobs;

class SlackSender extends SenderBase
{
    use Notifiable;
    use WebhookTrait;

    /**
     * @var mixed
     */
    protected $name;

    /**
     * @var mixed
     */
    protected $icon;

    /**
     * @var bool
     */
    protected $mention_here = false;

    /**
     * @var array<int, mixed>
     */
    protected $mention_users = [];

    /**
     * Create a new notification instance.
     *
     * @param mixed $webhook_url
     * @param mixed $subject
     * @param mixed $body
     * @param array<string, mixed> $options
     * @return void
     */
    public function __construct($webhook_url, $subject, $body, array $options = [])
    {
        $this->name = $options['webhook_name'] ?? config('exment.slack_from_name') ?? System::site_name();
        $this->icon = $options['webhook_icon'] ?? config('exment.slack_from_icon') ?? ':information_source:';
        $this->mention_here = $options['mention_here'] ?? false;
        $this->mention_users = $options['mention_users'] ?? [];
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
     * @return SlackSender
     */
    public static function make($webhook_url, $subject, $body, $options): SlackSender
    {
        return new self($webhook_url, $subject, $body, $options);
    }


    /**
     * @return string|null
     */
    protected function routeNotificationForSlack()
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
        $slack_content = $this->editContent();
        // send slack message
        $this->notify(new Jobs\SlackSendJob($this->name, $this->icon, $slack_content));
    }

    /**
     * replace url to slack format.
     *
     * @return string
     */
    protected function editContent()
    {
        $content = $this->subject . "\n*************************\n" . $this->body;

        $mentions = [];
        if ($this->mention_here) {
            $mentions[] = '<!here>';
        }
        foreach ($this->mention_users as $mention_user) {
            if (is_nullorempty($mention_user)) {
                continue;
            }
            $mentions[] = "<@$mention_user>";
        }
        if (!empty($mentions)) {
            $content = implode(' ', $mentions) . "\n". $content;
        }

        preg_match_all(Define::RULES_REGEX_LINK_FORMAT, $content, $matches);

        // @phpstan-ignore-next-line
        if (isset($matches)) {
            for ($i = 0; $i < count($matches[1]); $i++) {
                $match = $matches[1][$i];
                $matchString = $matches[0][$i];
                $matchName = $matches[2][$i];
                $str = "<$match|$matchName>";
                $content = str_replace($matchString, $str, $content);
            }
        }

        return $content;
    }
}
