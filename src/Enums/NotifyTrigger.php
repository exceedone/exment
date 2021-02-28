<?php

namespace Exceedone\Exment\Enums;

class NotifyTrigger extends EnumBase
{
    const TIME = "1";
    const CREATE_UPDATE_DATA = "2";
    const BUTTON = "3";
    const WORKFLOW = "4";
    const PUBLIC_FORM_COMPLETE_USER = "5";
    const PUBLIC_FORM_COMPLETE_ADMIN = "6";
    const PUBLIC_FORM_ERROR = "7";

    public static function CUSTOM_TABLES(){
        return [
            static::TIME,
            static::CREATE_UPDATE_DATA,
            static::BUTTON,
        ];
    }

    public function getDefaultMailKeyName()
    {
        switch ($this->getValue()) {
            case static::TIME:
                return MailKeyName::TIME_NOTIFY;
                
            case static::CREATE_UPDATE_DATA:
                return MailKeyName::DATA_SAVED_NOTIFY;

            case static::BUTTON:
                return null;
                
            case static::WORKFLOW:
                return MailKeyName::WORKFLOW_NOTIFY;
        }
    }
}
