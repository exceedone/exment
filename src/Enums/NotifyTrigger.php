<?php

namespace Exceedone\Exment\Enums;

class NotifyTrigger extends EnumBase
{
    public const TIME = "1";
    public const CREATE_UPDATE_DATA = "2";
    public const BUTTON = "3";
    public const WORKFLOW = "4";
    public const PUBLIC_FORM_COMPLETE_USER = "5";
    public const PUBLIC_FORM_COMPLETE_ADMIN = "6";
    public const PUBLIC_FORM_ERROR = "7";

    public static function CUSTOM_TABLES()
    {
        return [
            static::TIME,
            static::CREATE_UPDATE_DATA,
            static::BUTTON,
        ];
    }

    public static function PUBLIC_FORMS()
    {
        return [
            static::PUBLIC_FORM_COMPLETE_USER,
            static::PUBLIC_FORM_COMPLETE_ADMIN,
            static::PUBLIC_FORM_ERROR,
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
