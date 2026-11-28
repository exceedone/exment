<?php

namespace Exceedone\Exment\Services\Update;

use Exceedone\Exment\Services\BackupRestore\Backup;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * System update service: maintenance mode, backup and exment:update.
 * The packages themselves are updated with composer before this.
 */
class UpdateService
{
    /**
     * @param array<string, mixed> $options maintenance, backup, publish (bool); output (OutputInterface|null: where exment:update writes)
     * @return void
     * @throws \Exceedone\Exment\Exceptions\BackupFailedException when the backup fails (the update is not run)
     */
    public static function update(array $options = [])
    {
        \Exment::setTimeLimitLong();

        $options = array_merge([
            'maintenance' => true, // whether execute maintenance
            'backup' => true, // whether execute backup
            'publish' => true, // whether execute publish(update)
            'output' => null, // where exment:update writes (its notices)
        ], $options);

        try {
            if (boolval($options['maintenance'])) {
                \Artisan::call('down');
            }

            if (boolval($options['backup'])) {
                static::callBackup();
            }

            if (boolval($options['publish'])) {
                static::callPublish($options['output'] instanceof OutputInterface ? $options['output'] : null);
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

    /**
     * @param OutputInterface|null $output where exment:update writes (its notices), null: nowhere
     * @return void
     */
    public static function callPublish(?OutputInterface $output = null)
    {
        // the administrator chose the update: the database is updated also on a production site
        \Artisan::call('exment:update', ['--force' => true], $output);
    }
}
