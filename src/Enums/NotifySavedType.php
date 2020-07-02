<?php

namespace Exceedone\Exment\Enums;

class NotifySavedType extends EnumBase
{
    const CREATE = "created";
    const UPDATE = "updated";
    const DELETE = "deleted";
    const SHARE = "shared";
    const COMMENT = "comment";
    const ATTACHMENT = "attachmented";

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
     * @param CustomValue $custom_value
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
