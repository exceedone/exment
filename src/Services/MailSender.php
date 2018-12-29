<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;  
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

    protected $mail_template;
    protected $prms;
    protected $custom_value;
    
    public function __construct($mail_key_name, $to)
    {
        $this->from = null;
        $this->to = $to;
        $this->cc = [];
        $this->bcc = [];
        $this->prms = [];

        // get mail template
        if (is_numeric($mail_key_name)) {
            $this->mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_key_name);
        } else {
            $this->mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)
                ::where('value->mail_key_name', $mail_key_name)->first();
        }
        // if not found, return exception
        if (is_null($this->mail_template)) {
            throw new Exception("No MailTemplate. Please set mail template. mail_template:$mail_key_name");
        }
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
    
    public function to($to)
    {
        $this->to = $to;
        return $this;
    }
    
    public function cc($cc)
    {
        $this->cc = $cc;
        return $this;
    }
    
    public function bcc($bcc)
    {
        $this->bcc = $bcc;
        return $this;
    }

    public function custom_value($custom_value)
    {
        $this->custom_value = $custom_value;
        return $this;
    }
    
    public function prms($prms)
    {
        $this->prms = $prms;
        return $this;
    }
    
    /**
     * Send Mail
     *
     */
    public function send()
    {
        $this->sendMail(
            $this->replaceWord($this->mail_template->getValue('mail_subject')),
            $this->replaceWord($this->mail_template->getValue('mail_body'))
        );
    }

    /**
     * replace subject or body words.
     */
    protected function replaceWord(string $target)
    {
        $target = replaceTextFromFormat($target, $this->custom_value, [
            'matchBeforeCallback' => function($length_array, $matchKey, $format, $custom_value, $options){
                // if has prms using $match, return value
                $matchKey = str_replace(":", ".", $matchKey);
                if(array_has($this->prms, $matchKey)){
                    return array_get($this->prms, $matchKey);
                }
                return null;
            }
        ]);

        return $target;
    }

    /**
     * send
     */
    protected function sendMail($subject, $body)
    {
        Mail::send([], [], function ($message) use ($subject, $body) {
            $message->to($this->to)->subject($subject);
            $message->from(isset($this->from) ? $this->from : System::system_mail_from());
            if (count($this->cc) > 0) {
                $message->cc($this->cc);
            }
            if (count($this->bcc) > 0) {
                $message->bcc($this->bcc);
            }
            // replace \r\n
            $message->setBody(preg_replace("/\r\n|\r|\n/", "<br />", $body), 'text/html');
        });
    }
}
