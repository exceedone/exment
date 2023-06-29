<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Jobs;

class NavbarSender extends SenderBase
{
    use Notifiable;

    protected $notify_id;
    protected $custom_value;
    protected $custom_table_id;
    protected $user;

    /**
     * Create a new notification instance.
     *
     * @return void
     * @phpstan-ignore-next-line
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
     * @param string $notify_id
     * @param string $subject
     * @param string $body
     * @param array $options
     * @return NavbarSender
     */
    public static function make($notify_id, $subject, $body, $options): NavbarSender
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

    public function custom_table($custom_table)
    {
        if (isset($custom_table)) {
            $this->custom_table_id = $custom_table->id;
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
            $target_user_id = $this->user->getUserId();
        } elseif ($this->user instanceof NotifyTarget) {
            $target_user_id = $this->user->id();
        } elseif (is_numeric($this->user)) {
            $target_user_id = $this->user;
        }

        if (!isset($target_user_id)) {
            return;
        }

        $parent_id = isset($this->custom_value) ? array_get($this->custom_value, 'id') : null;

        $parent_type = null;
        if ($this->custom_table_id) {
            $custom_table = CustomTable::getEloquent($this->custom_table_id);
            $parent_type = $custom_table ? $custom_table->table_name : null;
        }
        if (is_nullorempty($parent_type)) {
            $parent_type = (isset($this->custom_value) ? $this->custom_value->custom_table->table_name : null);
        }

        // send slack message
        $this->notify(new Jobs\NavbarJob(
            $this->subject,
            $this->body,
            $this->notify_id ?? -1,
            $target_user_id,
            \Exment::getUserId() ?? null,
            $parent_id,
            $parent_type
        ));
    }

    /**
     * Get the value of the notifiable's primary key.
     *
     * @return string|null
     */
    public function getKey()
    {
        return $this->custom_value ? $this->custom_value->id : null;
    }
}
