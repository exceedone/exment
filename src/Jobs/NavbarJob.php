<?php

namespace Exceedone\Exment\Jobs;

use Illuminate\Notifications\Notification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Exceedone\Exment\Notifications\Navbar\NavbarChannel;
use Exceedone\Exment\Model\NotifyNavbar;

class NavbarJob extends Notification implements ShouldQueue
{
    use JobTrait;

    protected $content;
    protected $subject;

    protected $notify_id;

    /**
     * Notify target user id
     *
     * @var string
     */
    protected $target_user_id;

    /**
     * Notify triggered user id
     *
     * @var string
     */
    protected $trigger_user_id;

    /**
     * Notify target data id
     *
     * @var string
     */
    protected $parent_id;

    /**
     * Notify target data table name
     *
     * @var string
     */
    protected $parent_type;

    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($subject, $content, $notify_id, $target_user_id, $trigger_user_id, $parent_id, $parent_type)
    {
        $this->content = $content;
        $this->subject = $subject;
        $this->notify_id = $notify_id;
        $this->target_user_id = $target_user_id;
        $this->trigger_user_id = $trigger_user_id;
        $this->parent_id = $parent_id;
        $this->parent_type = $parent_type;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [NavbarChannel::class];
    }

    /**
     * Get the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     */
    public function toNavbar($notifiable)
    {
        // save data
        $notify_navbar = new NotifyNavbar();
        $notify_navbar->notify_id = $this->notify_id ?? -1;
        $notify_navbar->parent_id = $this->parent_id;
        $notify_navbar->parent_type = $this->parent_type;

        $notify_navbar->notify_subject = $this->subject;
        $notify_navbar->notify_body = $this->content;
        $notify_navbar->target_user_id = $this->target_user_id;
        $notify_navbar->trigger_user_id = $this->trigger_user_id;
        $notify_navbar->save();
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
