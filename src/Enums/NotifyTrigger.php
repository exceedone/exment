<?php

namespace Exceedone\Exment\Enums;

class NotifyTrigger extends EnumBase
{
    const TIME = "1";
    const CREATE_UPDATE_DATA = "2";
    const BUTTON = "3";
    const WORKFLOW = "4";

    public function getDefaultMailKeyName()
    {
        switch ($this->getValue()) {
            case static::TIME:
                return MailKeyName::TIME_NOTIFY;
                
            case static::CREATE_UPDATE_DATA:
                return MailKeyName::DATA_SAVED_NOTIFY;

            case static::BUTTON:
                return null;
        }
    }
}
