<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyTarget;

trait MailHistoryTrait
{
    /**
     * @var MailHistory
     */
    protected $mailHistory;


    /**
     * Get the value of mailHistory
     *
     * @return  MailHistory
     */
    public function getMailHistory()
    {
        return $this->mailHistory;
    }

    /**
     * Set the value of mailHistory
     *
     * @param  MailHistory  $mailHistory
     *
     * @return  self
     */
    public function setMailHistory(MailHistory $mailHistory)
    {
        $this->mailHistory = $mailHistory;

        return $this;
    }

    /**
     * Get user id.
     * @return string|null
     */
    public function getUserId()
    {
        return $this->mailHistory->getUserId();
    }


    /**
     * Target mail template's id
     * @return string|null
     */
    public function getMailTemplateId()
    {
        return $this->mailHistory->getMailTemplateId();
    }

    /**
     * Target custom value's id
     * @return string|null
     */
    public function getParentId()
    {
        return $this->mailHistory->getParentId();
    }

    /**
     * Target custom value's table name
     * @return string|null
     */
    public function getParentType()
    {
        return $this->mailHistory->getParentType();
    }


    /**
     * Get Custom Value
     *
     * @return null|CustomValue
     */
    public function getCustomValue(): ?CustomValue
    {
        return $this->mailHistory->getCustomValue();
    }

    /**
     * Whether history
     * @return bool
     */
    public function isSetHistory(): bool
    {
        return $this->mailHistory->isSetHistory();
    }

    /**
     * Whether history body
     * @return bool
     */
    public function isSetHistoryBody(): bool
    {
        return $this->mailHistory->isSetHistoryBody();
    }

    /**
     * Set the value of user
     *
     * @param  string|CustomValue|NotifyTarget|null  $user
     * @return MailHistory
     */
    public function setUser($user)
    {
        return $this->mailHistory->setUser($user);
    }

    public function setCustomValue(?CustomValue $custom_value)
    {
        return $this->mailHistory->setCustomValue($custom_value);
    }

    public function setHistory(bool $isSetHistory)
    {
        return $this->mailHistory->setHistory($isSetHistory);
    }

    public function setHistoryBody(bool $isSetHistoryBody)
    {
        return $this->mailHistory->setHistoryBody($isSetHistoryBody);
    }

    /**
     * Set the value of mail_template
     *
     * @param string|CustomValue|null $mail_template
     * @return MailHistory
     */
    public function setMailTemplate($mail_template)
    {
        return $this->mailHistory->setMailTemplate($mail_template);
    }
}
