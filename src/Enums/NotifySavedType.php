<?php

namespace Exceedone\Exment\Enums;

class NotifySavedType extends EnumBase
{
    const CREATE = "created";
    const UPDATE = "updated";
    const SHARE = "shared";
    const COMMENT = "comment";

    public function getLabel(){
        switch($this){
            case static::CREATE;
                return exmtrans('common.created');
                
            case static::UPDATE;
                return exmtrans('common.updated');
        
            case static::SHARE;
                return exmtrans('common.shared');
        
            case static::COMMENT;
                return exmtrans('common.comment');
        }
    }
}
