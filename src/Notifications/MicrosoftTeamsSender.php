<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notification;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Jobs;

class MicrosoftTeamsSender
{
    protected $subject;
    protected $body;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($subject, $body)
    {
        //$this->name = config('exment.slack_from_name') ?? System::site_name();
        //$this->icon = config('exment.slack_from_icon') ?? ':information_source:';
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
        $teams_content = static::editContent($this->subject, $this->body);
        // send slack message
        $notify->notify(new Jobs\MicrosoftTeamsJob($this->subject, $teams_content));
    }

    /**
     * replace url to slack format.
     */
    protected static function editContent($subject, $body)
    {
        $content = $body;
        preg_match_all(Define::RULES_REGEX_LINK_FORMAT, $content, $matches);

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
