<?php

namespace Exceedone\Exment\Enums;

class NotifySavedType extends EnumBase
{
    public const CREATE = "created";
    public const UPDATE = "updated";
    public const DELETE = "deleted";
    public const SHARE = "shared";
    public const COMMENT = "comment";
    public const ATTACHMENT = "attachmented";

    public function getLabel()
    {
        switch ($this) {
            case static::CREATE:
                return exmtrans('common.created');

            case static::UPDATE:
                return exmtrans('common.updated');

            case static::DELETE:
                return exmtrans('common.deleted');

            case static::SHARE:
                return exmtrans('common.shared');

            case static::COMMENT:
                return exmtrans('common.comment');

            case static::ATTACHMENT:
                return exmtrans('common.attachmented');
        }
    }

    /**
     * Get target user name.
     *
     * @param \Exceedone\Exment\Model\CustomValue $custom_value
     * @return string
     */
    public function getTargetUserName($custom_value)
    {
        switch ($this) {
            case static::CREATE:
            case static::UPDATE:
            case static::DELETE:
                return $custom_value->updated_user;

            case static::SHARE:
            case static::COMMENT:
            case static::ATTACHMENT:
                return \Exment::user()->base_user->label;
        }
    }
}
