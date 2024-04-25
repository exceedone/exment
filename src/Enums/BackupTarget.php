<?php

namespace Exceedone\Exment\Enums;

use Exceedone\Exment\Model\Define;

class BackupTarget extends EnumBase
{
    public const DATABASE = 'database';
    public const PLUGIN = 'plugin';
    public const ATTACHMENT = 'attachment';
    public const LOG = 'log';
    public const CONFIG = 'config';

    /**
     * Get backup target disk and relative path
     *
     * @param string|array $target
     * @return array|null
     */
    public static function dirOrDisk($target)
    {
        if (is_array($target)) {
            if (count($target) < 2) {
                $target = null;
            } elseif (count($target) >= 3 && $target[1] == 'storage' && $target[2] == 'logs') {
                $target = static::LOG;
            } elseif (count($target) >= 4 && $target[1] == 'storage' && $target[2] == 'app' && $target[3] == 'plugins') {
                $target = static::PLUGIN;
            } else {
                $target = $target[1];
            }
        }

        // target is array,
        // [0] is disk or path. if path, diectory to project path
        // [1] is backup from path and to
        // [2] is key name
        switch ($target) {
            case static::PLUGIN:
                return [\Storage::disk(Define::DISKNAME_PLUGIN_SYNC), path_join("storage", "app", "plugins"), static::PLUGIN];
            case static::LOG:
            case 'logs':
                return [base_path(path_join("storage", "logs")), "logs", static::LOG];
            case static::ATTACHMENT:
            case 'storage':
                return [\Storage::disk(Define::DISKNAME_ADMIN), path_join("storage", "app", "admin"), static::ATTACHMENT];
            case static::CONFIG:
                return [base_path('config'), "config", static::CONFIG];
        }
        return null;
    }
}
