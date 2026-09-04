<?php

namespace Exceedone\Exment\Notifications\Mail;

trait MailInfoTrait
{
    /**
     * @var MailInfo
     */
    protected $mailInfo;


    /**
     * Get the value of mailInfo
     *
     * @return  MailInfo
     */
    public function getMailInfo()
    {
        return $this->mailInfo;
    }

    /**
     * Set the value of mailInfo
     *
     * @param  MailInfo  $mailInfo
     *
     * @return  self
     */
    public function setMailInfo(MailInfo $mailInfo)
    {
        $this->mailInfo = $mailInfo;

        return $this;
    }


    /**
     * @return string
     */
    public function getFrom(): string
    {
        return $this->mailInfo->getFrom();
    }

    /**
     * @return string|null
     */
    public function getFromName(): ?string
    {
        return $this->mailInfo->getFromName();
    }

    /**
     * @return array
     */
    // @phpstan-ignore-next-line
    public function getTo(): array
    {
        return $this->mailInfo->getTo();
    }

    /**
     * @return array
     */
    // @phpstan-ignore-next-line
    public function getCc(): array
    {
        return $this->mailInfo->getCc();
    }

    /**
     * @return array
     */
    // @phpstan-ignore-next-line
    public function getBcc(): array
    {
        return $this->mailInfo->getBcc();
    }

    /**
     * @return string
     */
    public function getSubject(): ?string
    {
        return $this->mailInfo->getSubject();
    }

    /**
     * @return string
     */
    public function getBody(): ?string
    {
        return $this->mailInfo->getBody();
    }

    /**
     * @return string
     */
    public function getBodyType(): ?string
    {
        return $this->mailInfo->getBodyType();
    }

    /**
     * @return array
     */
    // @phpstan-ignore-next-line
    public function getAttachments(): array
    {
        return $this->mailInfo->getAttachments();
    }

    /**
     * @return bool
     */
    public function getUsePassword(): bool
    {
        return $this->mailInfo->getUsePassword();
    }

    /**
     * @return string
     */
    public function getPassword(): ?string
    {
        return $this->mailInfo->getPassword();
    }



    // @phpstan-ignore-next-line
    public function setFrom($from)
    {
        return $this->mailInfo->setFrom($from);
    }

    // @phpstan-ignore-next-line
    public function setFromName($fromName)
    {
        return $this->mailInfo->setFromName($fromName);
    }

    // @phpstan-ignore-next-line
    public function setTo($to)
    {
        return $this->mailInfo->setTo($to);
    }

    // @phpstan-ignore-next-line
    public function setCc($cc)
    {
        return $this->mailInfo->setCc($cc);
    }

    // @phpstan-ignore-next-line
    public function setBcc($bcc)
    {
        return $this->mailInfo->setBcc($bcc);
    }

    // @phpstan-ignore-next-line
    public function setSubject($subject)
    {
        return $this->mailInfo->setSubject($subject);
    }

    // @phpstan-ignore-next-line
    public function setBody($body)
    {
        return $this->mailInfo->setBody($body);
    }

    // @phpstan-ignore-next-line
    public function setBodyType($bodyType)
    {
        return $this->mailInfo->setBodyType($bodyType);
    }

    // @phpstan-ignore-next-line
    public function setUsePassword($usePassword)
    {
        return $this->mailInfo->setUsePassword($usePassword);
    }

    // @phpstan-ignore-next-line
    public function setPassword($password)
    {
        return $this->mailInfo->setPassword($password);
    }


    // @phpstan-ignore-next-line
    public function setAttachments($attachments)
    {
        return $this->mailInfo->setAttachments($attachments);
    }
}
