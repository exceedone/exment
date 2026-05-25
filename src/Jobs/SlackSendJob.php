<?php

namespace Exceedone\Exment\Jobs;

use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\SlackMessage;

class SlackSendJob extends Notification implements ShouldQueue
{
    use JobTrait;

    // @phpstan-ignore-next-line
    protected $name;
    // @phpstan-ignore-next-line
    protected $icon;
    // @phpstan-ignore-next-line
    protected $content;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    // @phpstan-ignore-next-line
    public function __construct($name, $icon, $content)
    {
        $this->name = $name;
        $this->icon = $icon;
        $this->content = $content;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    // @phpstan-ignore-next-line
    public function via($notifiable)
    {
        return ['slack'];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return SlackMessage
     */
    public function toSlack($notifiable)
    {
        return (new SlackMessage())
                ->from($this->name, $this->icon)
                ->content($this->content);
    }

    /**
     * Get the array representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    // @phpstan-ignore-next-line
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }
}
