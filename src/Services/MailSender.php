<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\MailTemplate;
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
    
    public function __construct($mail_name, $to)
    {
        $this->from = null;
        $this->to = $to;
        $this->cc = [];
        $this->bcc = [];

        // get mail template
        if (is_numeric($mail_name)) {
            $this->mail_template = MailTemplate::find($mail_name);
        } else {
            $this->mail_template = MailTemplate::where('mail_name', $mail_name)->first();
        }
        // if not found, return exception
        if (is_null($this->mail_template)) {
            throw new Exception("No MailTemplate. Please set mail template. mail_template:$this->mail_template");
        }
        $this->prms = [];
    }

    public static function make($mail_name, $to)
    {
        $sender = new MailSender($mail_name, $to);
        
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
            $this->replaceWord($this->mail_template->mail_subject),
            $this->replaceWord($this->mail_template->mail_body)
        );
    }

    /**
     * replace subject or body words.
     */
    protected function replaceWord(string $target)
    {
        // get key name using regex
        $count = preg_match_all('/\\$\\{(.+?)\\}/u', $target, $match);

        // replace words
        for ($i = 0; $i < $count; $i++) {
            // split keyname (delimiter .)
            $replacedWord = $this->getTargetWord($match[1][$i]);

            $target = str_replace($match[0][$i], $replacedWord, $target);
        }

        return $target;
    }

    protected function getTargetWord(string $matchKey)
    {
        // split keyname (delimiter .)
        $keys = explode(".", $matchKey);

        //count($keys) > 1 and key[0] == "system", get system value
        if (count($keys) >= 2 && $keys[0] == "system") {
            // get System function
            if (System::hasFunction($keys[1])) {
                return System::{$keys[1]}();
            }

            // get static value
            if ($keys[1] == "login_url") {
                return admin_url("auth/login");
            }
            if ($keys[1] == "system_url") {
                return admin_url("");
            }
        }

        
        return array_get($this->prms, $matchKey);
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
