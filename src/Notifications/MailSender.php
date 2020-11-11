<?php
namespace Exceedone\Exment\Notifications;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Jobs\MailSendJob;
use Illuminate\Support\Facades\Mail;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Notifications\Notifiable;

/**
 * Send Mail System
 */
class MailSender extends SenderBase
{
    use Notifiable;

    protected $from;
    protected $to;
    protected $cc;
    protected $bcc;
    protected $attachments;

    protected $mail_template;
    protected $prms;
    protected $custom_value;
    protected $user;
    protected $history_body;
    protected $replaceOptions;
    
    public function __construct($mail_key_name, $to)
    {
        $this->from = null;
        $this->to = $to;
        $this->cc = [];
        $this->bcc = [];
        $this->attachments = [];
        $this->prms = [];
        $this->replaceOptions = [];
        $this->history_body = true;

        // get mail template
        if (is_null($mail_key_name)) {
            return;
        } elseif ($mail_key_name instanceof CustomValue) {
            $this->mail_template = $mail_key_name;
        } elseif (is_numeric($mail_key_name)) {
            $this->mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_key_name);
        } else {
            $this->mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)
                ::where('value->mail_key_name', $mail_key_name)->first();
        }
        // if not found, return exception
        if (is_null($this->mail_template)) {
            throw new NoMailTemplateException($mail_key_name);
        }
        $this->subject = $this->mail_template->getValue('mail_subject');
        $this->body = $this->mail_template->getJoinedBody();
    }

    public static function make($mail_key_name, $to)
    {
        $sender = new MailSender($mail_key_name, $to);
        
        return $sender;
    }

    public function from($from)
    {
        $this->from = $from;
        return $this;
    }
    
    /**
     * mail TO. support mail address or User model
     */
    public function to($to)
    {
        if (isset($to)) {
            $this->to = $to;
        }

        return $this;
    }
    
    /**
     * mail CC. support mail address or User model
     */
    public function cc($cc)
    {
        if (isset($cc)) {
            $this->cc = $cc;
        }

        return $this;
    }
    
    /**
     * mail BCC. support mail address or User model
     */
    public function bcc($bcc)
    {
        if (isset($bcc)) {
            $this->bcc = $bcc;
        }

        return $this;
    }

    public function subject($subject)
    {
        if (isset($subject)) {
            $this->subject = $subject;
        }

        return $this;
    }

    public function body($body)
    {
        if (isset($body)) {
            $this->body = $body;
        }
        
        return $this;
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

    public function attachments($attachments)
    {
        if (isset($attachments)) {
            $this->attachments = $attachments;
        }

        return $this;
    }
    
    public function prms($prms)
    {
        if (isset($prms)) {
            $this->prms = $prms;
        }

        return $this;
    }
    
    public function disableHistoryBody()
    {
        $this->history_body = false;
        return $this;
    }
    
    public function replaceOptions($replaceOptions)
    {
        $this->replaceOptions = $replaceOptions;
        return $this;
    }
    
    /**
     * Get to address.
     *
     * @return string|null
     */
    public function getTo()
    {
        return arrayToString(NotifyService::getAddresses($this->to));
    }

    /**
     * Get attachments
     *
     * @return array|null
     */
    public function getAttachments()
    {
        return $this->attachments;
    }
    
    /**
     * Get the value of the notifiable's primary key.
     *
     * @return 
     * @return string|null
     */
    public function getKey()
    {
        return $this->getTo();
    }

    /**
     * Send Mail
     *
     */
    public function send()
    {
        // get subject
        $subject = NotifyService::replaceWord($this->subject, $this->custom_value, $this->prms, $this->replaceOptions);
        $body = NotifyService::replaceWord($this->body, $this->custom_value, $this->prms, $this->replaceOptions);

        $job = new MailSendJob($this->from,
            $this->to,
            $subject,
            $body,
            $this->mail_template,
            [
                'cc' => $this->cc,
                'bcc' => $this->bcc,
                'custom_value' => $this->custom_value,
                'user' => $this->user,
                'history_body' => $this->history_body,
                'attachments' => $this->attachments,
                'prms' => $this->prms,
            ]
        );
        $this->notify($job);

        return true;
    }
    
}
