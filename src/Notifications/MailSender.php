<?php

namespace Exceedone\Exment\Notifications;

use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\Traits\MailTemplateTrait;
use Illuminate\Support\Facades\Mail;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Notifications\Mail\MailHistory;
use Exceedone\Exment\Notifications\Mail\MailInfoTrait;
use Exceedone\Exment\Notifications\Mail\MailHistoryTrait;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Notifications\Notifiable;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Send Mail System
 */
class MailSender extends SenderBase
{
    use Notifiable;
    use MailInfoTrait;
    use MailHistoryTrait;


    protected $prms = [];
    protected $replaceOptions = [];
    protected $final_user;

    /**
     * @param $mail_template
     * @param $to
     * @throws NoMailTemplateException
     */
    public function __construct($mail_template, $to)
    {
        $this->mailInfo = new MailInfo();
        $this->mailHistory = new MailHistory();

        $this->setTo($to);
        $this->setPassword(make_password(16, ['mark' => false]));
        $this->setUsePassword(boolval(config('exment.archive_attachment', false)));

        // get mail template
        $mail_template = $this->getMailTemplateFromKey($mail_template);
        if (!is_nullorempty($mail_template)) {
            $this->mailHistory->setMailTemplate($mail_template);
            $this->setSubject($mail_template->getValue('mail_subject'));
            /** @phpstan-ignore-next-line Maybe need reflection. */
            $this->setBody($mail_template->getJoinedBody());

            $this->setFromName($mail_template->getValue('mail_from_view_name'));
        }
    }

    public static function make($mail_template, $to)
    {
        $sender = new MailSender($mail_template, $to);

        return $sender;
    }

    public function from($from)
    {
        $this->setFrom($from);
        return $this;
    }

    /**
     * mail TO. support mail address or User model
     */
    public function to($to)
    {
        if (isset($to)) {
            $this->setTo($to);
        }

        return $this;
    }

    /**
     * mail CC. support mail address or User model
     */
    public function cc($cc)
    {
        if (isset($cc)) {
            $this->setCc($cc);
        }

        return $this;
    }

    /**
     * mail BCC. support mail address or User model
     */
    public function bcc($bcc)
    {
        if (isset($bcc)) {
            $this->setBcc($bcc);
        }

        return $this;
    }

    public function subject($subject)
    {
        if (isset($subject)) {
            $this->setSubject($subject);
        }

        return $this;
    }

    public function body($body)
    {
        if (isset($body)) {
            $this->setBody($body);
        }

        return $this;
    }

    public function attachments($attachments)
    {
        if (isset($attachments)) {
            if (!is_list($attachments)) {
                $attachments = [$attachments];
            }

            $this->setAttachments($attachments);
        }

        return $this;
    }

    public function custom_value($custom_value)
    {
        if (isset($custom_value)) {
            $this->setCustomValue($custom_value);
        }

        return $this;
    }

    public function user($user)
    {
        if (isset($user)) {
            $this->setUser($user);
            $this->setTo(NotifyService::getAddresses($user));
        }

        return $this;
    }

    public function disableHistoryBody()
    {
        $this->setHistoryBody(false);
        return $this;
    }

    public function prms($prms)
    {
        if (isset($prms)) {
            $this->prms = $prms;
        }

        return $this;
    }

    public function finalUser($final_user)
    {
        if (isset($final_user)) {
            $this->final_user = $final_user;
        }

        return $this;
    }

    public function replaceOptions($replaceOptions)
    {
        $this->replaceOptions = $replaceOptions;
        return $this;
    }


    /**
     * Get to address.
     * *This function result is string.*
     *
     * @return string
     */
    public function getTo()
    {
        return arrayToString($this->mailInfo->getTo());
    }

    /**
     * Get the value of the notifiable's primary key.
     *
     * @return string|null
     */
    public function getKey()
    {
        return $this->getTo();
    }


    /**
     * Send Mail
     */
    public function send()
    {
        $this->sendMail();
        $this->sendPasswordMail();
    }

    protected function sendMail()
    {
        // get subject
        $subject = NotifyService::replaceWord($this->getSubject(), $this->getCustomValue(), $this->prms, $this->replaceOptions);
        list($body, $bodyType) = $this->getBodyAndBodyType($this->getBody(), $this->prms, $this->replaceOptions);
        $fromName = NotifyService::replaceWord($this->getFromName(), $this->getCustomValue(), $this->prms, $this->replaceOptions);

        // set header as password
        if ($this->getUsePassword()) {
            $password_notify_header = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', 'password_notify_header')->first();
            if (isset($password_notify_header)) {
                list($headerBody, $headerBodyType) = $this->getBodyAndBodyType(array_get($password_notify_header->value, 'mail_body'));

                $body = $headerBody . $body;
            }
        }

        $this->setSubject($subject)
            ->setBody($body)
            ->setFromName($fromName)
            ->setBodyType($bodyType);

        $job = new MailSendJob(\Exment::user(), $this->final_user);
        $job->setMailInfo($this->mailInfo)
            ->setMailHistory($this->mailHistory);
        $this->notify($job);
    }


    protected function sendPasswordMail()
    {
        if (!boolval($this->getUsePassword())) {
            return;
        }

        // get password notify mail template
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', 'password_notify')->first();
        $subject = array_get($mail_template->value, 'mail_subject');
        $body = array_get($mail_template->value, 'mail_body');
        $fromName = array_get($mail_template->value, 'mail_from_view_name');

        $prms = $this->prms;
        $prms['zip_password'] = $this->getPassword();

        // get subject
        $subject = NotifyService::replaceWord($subject, $this->getCustomValue(), $prms);
        list($body, $bodyType) = $this->getBodyAndBodyType($body, $prms);
        $fromName = NotifyService::replaceWord($fromName, $this->getCustomValue(), $prms);

        // clone and replace value
        $mailInfo = clone $this->mailInfo;
        $mailHistory = clone $this->mailHistory;
        $mailInfo
            ->setSubject($subject)
            ->setBody($body)
            ->setFromName($fromName)
            ->setBodyType($bodyType)
            ->clearAttachments();
        $mailHistory
            ->setMailTemplate($mail_template)
            ->setHistory(false);

        $job = new MailSendJob(\Exment::user(), $this->final_user);
        $job->setMailInfo($mailInfo)
            ->setMailHistory($mailHistory);

        $this->notify($job);
    }


    /**
     * Get Body And Body Type(PLAIN, HTML)
     * Replace body break to <br/>, or <br /> to \n
     *
     * @param string $body
     * @return array offset 0 : $body, 1 : Type(PLAIN, HTML)
     */
    protected function getBodyAndBodyType($body, array $prms = [], array $replaceOptions = [])
    {
        $body = NotifyService::replaceWord($body, $this->getCustomValue(), $prms, $replaceOptions);

        if (isMatchString(System::system_mail_body_type(), Enums\MailBodyType::PLAIN)) {
            return [replaceBrTag($body), 'text/plain'];
        } else {
            return [replaceBreak($body, false), 'text/html'];
        }
    }


    /**
     * Get mail template from key
     *
     * @param CustomValue|string|null $mail_template
     * @return CustomValue|null
     */
    protected function getMailTemplateFromKey($mail_template): ?CustomValue
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
            $result = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', $mail_template)->first();
        }
        // if not found, return exception
        if (is_null($result)) {
            throw new NoMailTemplateException($mail_template);
        }

        return $result;
    }
}
