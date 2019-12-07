<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\Define;

class BackupTarget extends EnumBase
{
    const DATABASE = 'database';
    const PLUGIN = 'plugin';
    const ATTACHMENT = 'attachment';
    const LOG = 'log';
    const CONFIG = 'config';

    /**
     * Get backup target disk and relative path
     *
     * @param stirng $target
     * @return \Storage|string
     */
    public static function dirOrDisk($target)
    {
        switch ($target) {
            case static::PLUGIN:
                return [\Storage::disk(Define::DISKNAME_PLUGIN_SYNC), path_join("storage", "app", "plugins")];
            case static::ATTACHMENT:
            case 'storage':
                return [\Storage::disk(Define::DISKNAME_ADMIN), path_join("storage", "app", "admin")];
            case static::LOG:
                return path_join("storage", "logs");
            case static::CONFIG:
                return "config";
        }
        return null;
    }
}
