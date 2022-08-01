<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Model\System;

class MailInfo
{
    /**
     * @var string
     */
    protected $from;

    /**
     * from name
     * @var string
     */
    protected $fromName;

    /**
     * @var array
     */
    protected $to = [];

    /**
     * @var array
     */
    protected $cc = [];

    /**
     * @var array
     */
    protected $bcc = [];

    /**
     * @var string
     */
    protected $subject;

    /**
     * @var string
     */
    protected $body;

    /**
     * 'text/plain' or 'text/html'
     * @var string
     */
    protected $bodyType;

    /**
     * @var array
     */
    protected $attachments = [];

    /**
     * If use password attachments, set true.
     * @var bool
     */
    protected $usePassword = false;

    /**
     * @var string
     */
    protected $password;


    /**
     * @return string
     */
    public function getFrom(): string
    {
        return !is_nullorempty($this->from) ? $this->from : config('mail.from.address') ?? System::system_mail_from();
    }

    /**
     * @return string
     */
    public function getFromName(): ?string
    {
        $fromName = !is_nullorempty($this->fromName) ? $this->fromName : config('mail.from.name', System::system_mail_from_view_name());
        if (isMatchString($fromName, $this->getFrom())) {
            return null;
        }
        return $fromName;
    }

    /**
     * @return array
     */
    public function getTo(): array
    {
        return NotifyService::getAddresses($this->to);
    }

    /**
     * @return array
     */
    public function getCc(): array
    {
        return NotifyService::getAddresses($this->cc);
    }

    /**
     * @return array
     */
    public function getBcc(): array
    {
        return NotifyService::getAddresses($this->bcc);
    }

    /**
     * @return string
     */
    public function getSubject(): ?string
    {
        return $this->subject;
    }

    /**
     * @return string
     */
    public function getBody(): ?string
    {
        return $this->body;
    }

    /**
     * @return string
     */
    public function getBodyType(): ?string
    {
        return $this->bodyType;
    }

    /**
     * @return array
     */
    public function getAttachments(): array
    {
        return array_filter($this->attachments);
    }

    /**
     * Get using password. Only contains attachments
     * @return bool
     */
    public function getUsePassword(): bool
    {
        return $this->usePassword && count($this->getAttachments()) > 0;
    }

    /**
     * @return string
     */
    public function getPassword(): ?string
    {
        return $this->password;
    }



    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    public function setFromName($fromName)
    {
        $this->fromName = $fromName;
        return $this;
    }

    public function setTo($to)
    {
        $this->to = array_merge($this->to, $this->convertArray($to));
        return $this;
    }

    public function setCc($cc)
    {
        $this->cc = array_merge($this->cc, $this->convertArray($cc));
        return $this;
    }

    public function setBcc($bcc)
    {
        $this->bcc = array_merge($this->bcc, $this->convertArray($bcc));
        return $this;
    }

    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }

    public function setBodyType($bodyType)
    {
        $this->bodyType = $bodyType;
        return $this;
    }

    public function setUsePassword($usePassword)
    {
        $this->usePassword = $usePassword;
        return $this;
    }

    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }


    public function setAttachments($attachments)
    {
        if (is_nullorempty($this->attachments)) {
            $this->attachments = [];
        }

        foreach ($attachments as $attachment) {
            if (is_null($obj = MailAttachment::make($attachment))) {
                continue;
            }
            $this->attachments[] = $obj;
        }

        return $this;
    }


    public function clearAttachments()
    {
        $this->attachments = [];
    }


    protected function convertArray($value)
    {
        if ($value instanceof \Illuminate\Database\Eloquent\Model || $value instanceof \Exceedone\Exment\Model\NotifyTarget) {
            return [$value];
        }
        return toArray($value);
    }
}
