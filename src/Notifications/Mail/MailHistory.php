<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyTarget;

class MailHistory
{
    /**
     * @var string|CustomValue|null
     */
    protected $mail_template;

    /**
     * @var string|CustomValue|LoginUser|NotifyTarget|null
     */
    protected $user;

    /**
     * Target custom value's id
     * @var string|null
     */
    protected $parent_id;

    /**
     * Target custom value's table name
     * @var string|null
     */
    protected $parent_type;

    /**
     * Target custom value.
     * @var CustomValue|null
     */
    protected $custom_value;


    /**
     * Whether history
     * @var bool
     */
    protected $isSetHistory = true;

    /**
     * Whether history body
     * @var bool
     */
    protected $isSetHistoryBody = true;


    /**
     * Get user id.
     * @return string|null
     */
    public function getUserId()
    {
        if ($this->user instanceof CustomValue) {
            return $this->user->getUserId();
        } elseif ($this->user instanceof LoginUser) {
            return $this->user->getUserId();
        } elseif ($this->user instanceof NotifyTarget) {
            return $this->user->id();
        }
        // pure email
        elseif (is_string($this->user)) {
            return $this->user;
        }
        return null;
    }


    /**
     * Target mail template's id
     * @return string|null
     */
    public function getMailTemplateId()
    {
        if ($this->mail_template instanceof CustomValue) {
            return $this->mail_template->id;
        }
        // pure email
        elseif (is_numeric($this->mail_template)) {
            return $this->mail_template;
        }
        return null;
    }

    /**
     * Target custom value's id
     * @return string|null
     */
    public function getParentId()
    {
        return $this->parent_id;
    }

    /**
     * Target custom value's table name
     * @return string|null
     */
    public function getParentType()
    {
        return $this->parent_type;
    }


    /**
     * Get Custom Value
     *
     * @return null|CustomValue
     */
    public function getCustomValue(): ?CustomValue
    {
        if (isset($this->custom_value)) {
            return $this->custom_value;
        }

        $custom_table = CustomTable::getEloquent($this->parent_type);
        if (!isset($custom_table)) {
            return null;
        }
        return $custom_table->getValueModel($this->parent_id);
    }

    /**
     * Whether history
     * @return bool
     */
    public function isSetHistory(): bool
    {
        return $this->isSetHistory;
    }

    /**
     * Whether history body
     * @return bool
     */
    public function isSetHistoryBody(): bool
    {
        return $this->isSetHistoryBody;
    }




    /**
     * Set the value of user
     *
     * @param  string|CustomValue|NotifyTarget|null  $user
     *
     * @return  self
     */
    public function setUser($user)
    {
        $this->user = $user;

        return $this;
    }

    public function setCustomValue(?CustomValue $custom_value)
    {
        $this->custom_value = $custom_value;
        $this->parent_id = $custom_value ? $custom_value->id : null;
        $this->parent_type = $custom_value ? $custom_value->custom_table->table_name : null;

        return $this;
    }

    public function setHistory(bool $isSetHistory)
    {
        $this->isSetHistory = $isSetHistory;
        return $this;
    }

    public function setHistoryBody(bool $isSetHistoryBody)
    {
        $this->isSetHistoryBody = $isSetHistoryBody;
        return $this;
    }

    /**
     * Set the value of mail_template
     *
     * @param  string|CustomValue|null  $mail_template
     *
     * @return  self
     */
    public function setMailTemplate($mail_template)
    {
        $this->mail_template = $mail_template;

        return $this;
    }
}
