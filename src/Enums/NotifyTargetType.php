<?php

namespace Exceedone\Exment\Enums;

class NotifyTargetType extends EnumBase
{
    const EMAIL = "1";
    const EMAIL_COLUMN = "2";
    const USER = "3";
    const ORGANIZATION = "4";

    public static function getNotifyFuncByTable($table_name){
        switch($table_name){
            case SystemTableName::USER:
                return 'getModelAsUser';
            case SystemTableName::ORGANIZATION:
                return 'getModelsAsOrganization';
        }
    }
}
