<?php

namespace Exceedone\Exment\Enums;

class NotifyTargetType extends EnumBase
{
    public const EMAIL = "1";
    public const EMAIL_COLUMN = "2";
    public const USER = "3";
    public const ORGANIZATION = "4";

    public static function getNotifyFuncByTable($table_name)
    {
        switch ($table_name) {
            case SystemTableName::USER:
                return 'getModelAsUser';
            case SystemTableName::ORGANIZATION:
                return 'getModelsAsOrganization';
        }
    }
}
