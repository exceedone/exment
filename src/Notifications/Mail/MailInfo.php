<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Model\System;

class MailInfo
{
    /**
     * @var string|null
     */
    protected $from;

    /**
     * from name
     * @var string|null
     */
    protected $fromName;

    /**
     * @var array<int, mixed>
     */
    protected $to = [];

    /**
     * @var array<int, mixed>
     */
    protected $cc = [];

    /**
     * @var array<int, mixed>
     */
    protected $bcc = [];

    /**
     * @var string|null
     */
    protected $subject;

    /**
     * @var string|null
     */
    protected $body;

    /**
     * 'text/plain' or 'text/html'
     * @var string|null
     */
    protected $bodyType;

    /**
     * @var array<int, mixed>
     */
    protected $attachments = [];

    /**
     * If use password attachments, set true.
     * @var bool
     */
    protected $usePassword = false;

    /**
     * @var string|null
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
    // @phpstan-ignore-next-line
    public function getTo(): array
    {
        return NotifyService::getAddresses($this->to);
    }

    /**
     * @return array
     */
    // @phpstan-ignore-next-line
    public function getCc(): array
    {
        return NotifyService::getAddresses($this->cc);
    }

    /**
     * @return array
     */
    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
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



    /**
     * @param mixed $from
     * @return $this
     */
    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }

    /**
     * @param mixed $fromName
     * @return $this
     */
    public function setFromName($fromName)
    {
        $this->fromName = $fromName;
        return $this;
    }

    /**
     * @param mixed $to
     * @return $this
     */
    public function setTo($to)
    {
        $this->to = array_merge($this->to, $this->convertArray($to));
        return $this;
    }

    /**
     * @param mixed $cc
     * @return $this
     */
    public function setCc($cc)
    {
        $this->cc = array_merge($this->cc, $this->convertArray($cc));
        return $this;
    }

    /**
     * @param mixed $bcc
     * @return $this
     */
    public function setBcc($bcc)
    {
        $this->bcc = array_merge($this->bcc, $this->convertArray($bcc));
        return $this;
    }

    /**
     * @param mixed $subject
     * @return $this
     */
    public function setSubject($subject)
    {
        $this->subject = $subject;
        return $this;
    }

    /**
     * @param mixed $body
     * @return $this
     */
    public function setBody($body)
    {
        $this->body = $body;
        return $this;
    }

    /**
     * @param mixed $bodyType
     * @return $this
     */
    public function setBodyType($bodyType)
    {
        $this->bodyType = $bodyType;
        return $this;
    }

    /**
     * @param mixed $usePassword
     * @return $this
     */
    public function setUsePassword($usePassword)
    {
        $this->usePassword = $usePassword;
        return $this;
    }

    /**
     * @param mixed $password
     * @return $this
     */
    public function setPassword($password)
    {
        $this->password = $password;
        return $this;
    }


    /**
     * @param mixed $attachments
     * @return $this
     */
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


    /**
     * @return void
     */
    public function clearAttachments()
    {
        $this->attachments = [];
    }


    /**
     * @param mixed $value
     * @return array<int, mixed>
     */
    protected function convertArray($value)
    {
        if ($value instanceof \Illuminate\Database\Eloquent\Model || $value instanceof \Exceedone\Exment\Model\NotifyTarget) {
            return [$value];
        }
        return toArray($value);
    }
}
