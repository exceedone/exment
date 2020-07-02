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
     * @param string $target
     * @return \Storage|string
     */
    public static function dirOrDisk($target)
    {
        if (is_array($target)) {
            if (count($target) < 2) {
                $target = null;
            } elseif (count($target) >= 3 && $target[1] == 'storage' && $target[2] == 'logs') {
                $target = static::LOG;
            } else {
                $target = $target[1];
            }
        }

        // target is array,
        // [0] is disk or path. if path, diectory to project path
        // [1] is backup from path and to
        switch ($target) {
            case static::PLUGIN:
                return [\Storage::disk(Define::DISKNAME_PLUGIN_SYNC), path_join("storage", "app", "plugins")];
            case static::LOG:
            case 'logs':
                return [base_path(path_join("storage", "logs")), "logs"];
            case static::ATTACHMENT:
            case 'storage':
                return [\Storage::disk(Define::DISKNAME_ADMIN), path_join("storage", "app", "admin")];
            case static::CONFIG:
                return [base_path('config'), "config"];
        }
        return null;
    }
}
