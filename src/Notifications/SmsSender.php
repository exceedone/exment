<?php

namespace Exceedone\Exment\Notifications;

use Illuminate\Notifications\Notifiable;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Jobs;
use Exceedone\Exment\Services\NotifyService;

class SmsSender extends SenderBase
{
    use Notifiable;
    
    protected $to;
    protected $content;
    protected $prms = [];
    
    /**
     * Create a new notification instance.
     *
     * @return void
     */
    public function __construct($mail_template, $to)
    {
        // get mail template
        $mail_template = $this->getMailTemplateFromKey($mail_template);
        if (!is_nullorempty($mail_template)) {
            $this->content = $mail_template->getJoinedBody();
        }
        $this->setTo($to);
    }

    public static function make($mail_template, $to)
    {
        $sender = new SmsSender($mail_template, $to);
        
        return $sender;
    }
    
    public function prms($prms)
    {
        if (isset($prms)) {
            $this->prms = $prms;
        }

        return $this;
    }

    public function setTo($to)
    {
        $to = preg_replace('/[^0-9]/', '', $to);

        $to =  ltrim($to, '0');

        if(substr($to, 0, 2) !== "81"){
            $to =  "81$to";
        }

        $this->to = $to;
    }

    /**
     * Send notify
     *
     * @return void
     */
    public function send()
    {
        $content = NotifyService::replaceWord($this->content, null, $this->prms);
        $this->notify(new Jobs\SmsSendJob($content));
    }
    
    public function routeNotificationForNexmo($notification)
    {
        return $this->to;
    }

    /**
     * Get mail template from key
     *
     * @param CustomValue|string|null $mail_template
     * @return CustomValue|null
     */
    protected function getMailTemplateFromKey($mail_template) : ?CustomValue
    {
        if (is_null($mail_template)) {
            return null;
        } elseif ($mail_template instanceof CustomValue) {
            return $mail_template;
        }
        
        $result = null;
        if (is_numeric($mail_template)) {
            $result = getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_template);
        } else {
            $result = getModelName(SystemTableName::MAIL_TEMPLATE)
                ::where('value->mail_key_name', $mail_template)->first();
        }
        // if not found, return exception
        if (is_null($result)) {
            throw new NoMailTemplateException($mail_template);
        }

        return $result;
    }
}
