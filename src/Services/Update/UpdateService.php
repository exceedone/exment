<?php

namespace Exceedone\Exment\Services\Update;

use Exceedone\Exment\Services\BackupRestore\Backup;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException;

/**
 * System update service
 */
class UpdateService
{
    // @phpstan-ignore-next-line
    public static function update(array $options = [])
    {
        \Exment::setTimeLimitLong();

        $options = array_merge([
            'maintenance' => true, // whether execute maintenance
            'backup' => true, // whether execute backup
            'publish' => true, // whether execute publish(update)
        ], $options);

        try {
            if (boolval($options['maintenance'])) {
                \Artisan::call('down');
            }

            if (boolval($options['backup'])) {
                static::callBackup();
            }

            if (boolval($options['publish'])) {
                static::callPublish();
            }
        } finally {
            if (boolval($options['maintenance'])) {
                \Artisan::call('up');
            }
        }
    }


    // @phpstan-ignore-next-line
    public static function callBackup()
    {
        $backup = new Backup();

        // check backup execute
        try {
            $backup->check();
            $backup->initBackupRestore()->execute();
        } catch (BackupRestoreNotSupportedException $ex) {
        } catch (BackupRestoreCheckException $ex) {
        } finally {
            $backup->diskService()->deleteTmpDirectory();
        }
    }

    // @phpstan-ignore-next-line
    public static function callPublish()
    {
        // the administrator chose the update: the database is updated also on a production site
        \Artisan::call('exment:update', ['--force' => true]);
    }
}
