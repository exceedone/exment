<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\MicrosoftTeams\MicrosoftTeamsMessage;

class MicrosoftTeamsSender extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($subject, $content)
    {
        //$this->name = config('exment.slack_from_name') ?? System::site_name();
        //$this->icon = config('exment.slack_from_icon') ?? ':information_source:';
        $this->content = $content;
        $this->subject = $subject;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [MicrosoftTeams\MicrosoftTeamsChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toChat($notifiable)
    {
        return (new MicrosoftTeamsMessage)
            ->content($this->content)
            ->title($this->subject);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }

    /**
     * replace url to slack format.
     */
    public static function editContent($subject, $body)
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
