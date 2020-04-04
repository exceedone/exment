<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notification;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Jobs;

class SlackSender
{
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($subject, $body)
    {
        $this->name = config('exment.slack_from_name') ?? System::site_name();
        $this->icon = config('exment.slack_from_icon') ?? ':information_source:';
        $this->subject = $subject;
        $this->body = $body;
    }

    /**
     * Send notify
     *
     * @return void
     */
    public function send($notify)
    {
        // replace word
        $slack_content = static::editContent($this->subject, $this->body);
        // send slack message
        $notify->notify(new Jobs\SlackSendJob($this->name, $this->icon, $slack_content));
    }

    /**
     * replace url to slack format.
     */
    public static function editContent($subject, $body)
    {
        $content = $subject . "\n*************************\n" . $body;

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
