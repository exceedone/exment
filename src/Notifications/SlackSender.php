<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Jobs;

class SlackSender
{
    use Notifiable;
    
    protected $name;
    protected $icon;
    protected $subject;
    protected $body;
    protected $webhook_url;
    protected $mention_here;
    protected $mention_users;
    
    /**
     * Create a new notification instance.
     *
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
     * @param string $webhook_url
     * @param string $subject
     * @param string $body
     * @return SlackSender
     */
    public static function make($webhook_url, $subject, $body, $options) : SlackSender
    {
        return new self($webhook_url, $subject, $body, $options);
    }


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
        $slack_content = static::editContent($this->subject, $this->body, $this->mention_here, $this->mention_users);
        // send slack message
        $this->notify(new Jobs\SlackSendJob($this->name, $this->icon, $slack_content));
    }

    /**
     * replace url to slack format.
     */
    public static function editContent($subject, $body, $mention_here = false, $mention_users = [])
    {
        $content = $subject . "\n*************************\n" . $body;

        $mentions = [];
        if ($mention_here) {
            $mentions[] = '<!here>';
        }
        foreach ($mention_users as $mention_user) {
            $mentions[] = "<@$mention_user>";
        }
        if (!empty($mentions)) {
            $content = implode(' ', $mentions) . "\n". $content;
        }

        preg_match_all(Define::RULES_REGEX_LINK_FORMAT, $content, $matches);

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
