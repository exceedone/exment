<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\MailTemplateType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Jobs\MailSendJob;
use Illuminate\Support\Facades\Mail;
use Exception;

/**
 * Send Mail System
 */
class MailSender
{
    protected $from;
    protected $to;
    protected $cc;
    protected $bcc;
    protected $subject;
    protected $body;
    protected $attachments;

    protected $mail_template;
    protected $prms;
    protected $custom_value;
    protected $user;
    protected $history_body;
    
    public function __construct($mail_key_name, $to)
    {
        $this->from = null;
        $this->to = $to;
        $this->cc = [];
        $this->bcc = [];
        $this->attachments = [];
        $this->prms = [];
        $this->history_body = true;

        // get mail template
        if ($mail_key_name instanceof CustomValue) {
            $this->mail_template = $mail_key_name;
        } elseif (is_numeric($mail_key_name)) {
            $this->mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_key_name);
        } else {
            $this->mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)
                ::where('value->mail_key_name', $mail_key_name)->first();
        }
        // if not found, return exception
        if (is_null($this->mail_template)) {
            throw new Exception("No MailTemplate. Please set mail template. mail_template:$mail_key_name");
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
        $this->to = $to;
        return $this;
    }
    
    /**
     * mail CC. support mail address or User model
     */
    public function cc($cc)
    {
        $this->cc = $cc;
        return $this;
    }
    
    /**
     * mail BCC. support mail address or User model
     */
    public function bcc($bcc)
    {
        $this->bcc = $bcc;
        return $this;
    }

    public function subject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    public function body($body)
    {
        $this->body = $body;
        return $this;
    }

    public function custom_value($custom_value)
    {
        $this->custom_value = $custom_value;
        return $this;
    }
    
    public function user($user)
    {
        $this->user = $user;
        return $this;
    }

    public function attachments($attachments)
    {
        $this->attachments = $attachments;
        return $this;
    }
    
    public function prms($prms)
    {
        $this->prms = $prms;
        return $this;
    }
    
    public function disableHistoryBody()
    {
        $this->history_body = false;
        return $this;
    }
    
    /**
     * Send Mail
     *
     */
    public function send()
    {
        // get subject
        $subject = $this->replaceWord($this->subject);
        $body = $this->replaceWord($this->body);

        // dispatch jobs
        MailSendJob::dispatch(
            $this->from,
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
                'attachments' => $this->attachments
            ]
        );
        return true;
    }

    /**
     * replace subject or body words.
     */
    protected function replaceWord(string $target)
    {
        $target = replaceTextFromFormat($target, $this->custom_value, [
            'matchBeforeCallback' => function ($length_array, $matchKey, $format, $custom_value, $options) {
                // if has prms using $match, return value
                $matchKey = str_replace(":", ".", $matchKey);
                if (array_has($this->prms, $matchKey)) {
                    return array_get($this->prms, $matchKey);
                }
                return null;
            }
        ]);

        return $target;
    }
}
