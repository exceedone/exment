<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;
use Illuminate\Notifications\Messages\SlackMessage;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;

class SlackSender extends Notification
{
    use Queueable;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($content)
    {
        $this->name = config('exment.slack_from_name') ?? System::site_name();
        $this->icon = config('exment.slack_from_icon') ?? ':information_source:';
        $this->content = $content;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['slack'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toSlack($notifiable)
    {
        return (new SlackMessage)
                ->from($this->name, $this->icon)
                ->content($this->content);
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
