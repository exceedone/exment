<?php

namespace Exceedone\Exment\Jobs;

use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Exceedone\Exment\Notifications\MicrosoftTeams\MicrosoftTeamsMessage;
use Exceedone\Exment\Notifications\MicrosoftTeams\MicrosoftTeamsChannel;

class MicrosoftTeamsJob extends Notification implements ShouldQueue
{
    use JobTrait;

    protected $content;
    protected $subject;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($subject, $content)
    {
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
        return [MicrosoftTeamsChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return MicrosoftTeamsMessage
     */
    public function toChat($notifiable)
    {
        return (new MicrosoftTeamsMessage())
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
}
