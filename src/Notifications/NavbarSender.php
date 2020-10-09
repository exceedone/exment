<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Notifications;

class NavbarSender
{
    use Notifiable;
    
    protected $notify_id;
    protected $subject;
    protected $body;
    protected $custom_value;
    protected $user;
    
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($notify_id, $subject, $body, array $options = [])
    {
        $this->notify_id = $notify_id;
        $this->subject = $subject;
        $this->body = $body;
    }

    /**
     * Initialize $this
     *
     * @param string $webhook_url
     * @param string $subject
     * @param string $body
     * @return SlackSender
     */
    public static function make($notify_id, $subject, $body, $options) : NavbarSender
    {
        return new self($notify_id, $subject, $body, $options);
    }

    public function custom_value($custom_value)
    {
        if (isset($custom_value)) {
            $this->custom_value = $custom_value;
        }

        return $this;
    }
    
    public function user($user)
    {
        if (isset($user)) {
            $this->user = $user;
        }

        return $this;
    }



    /**
     * Send notify
     *
     * @return void
     */
    public function send()
    {
        if ($this->user instanceof CustomValue) {
            $id = $this->user->getUserId();
        } elseif ($this->user instanceof NotifyTarget) {
            $id = $this->user->id();
        } elseif (is_numeric($this->user)) {
            $id = $this->user;
        }

        if (!isset($id)) {
            return;
        }

        // save data
        $notify_navbar = new NotifyNavbar;
        $notify_navbar->notify_id = $this->notify_id ?? -1;

        if (isset($this->custom_value)) {
            $notify_navbar->parent_id = array_get($this->custom_value, 'id');
            $notify_navbar->parent_type = $this->custom_value->custom_table->table_name;
        }

        $notify_navbar->notify_subject = $this->subject;
        $notify_navbar->notify_body = $this->body;
        $notify_navbar->target_user_id = $id;
        $notify_navbar->trigger_user_id = \Exment::getUserId() ?? null;
        $notify_navbar->save();
    }
}
