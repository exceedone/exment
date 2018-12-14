<?php

namespace Exceedone\Exment\Enums;

class BackupTarget extends EnumBase
{
    const DATABASE = 'database';
    const PLUGIN = 'plugin';
    const ATTACHMENT = 'attachment';
    const LOG = 'log';
    const CONFIG = 'config';

    public static function dir($target){
        switch($target){
            case static::PLUGIN:
                return "app\Plugins";
            case static::ATTACHMENT:
                return "storage\app\admin";
            case static::LOG:
                return "storage\logs";
            case static::CONFIG:
                return "config";
        }
        return null;
    }
}
