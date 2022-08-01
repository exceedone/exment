<?php

namespace Exceedone\Exment\Services\Update;

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Script\Event as ScriptEvent;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\BackupRestore\Backup;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRestoreNotSupportedException;

/**
 * System update service
 */
class UpdateService
{
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

            static::updateExment();

            if (boolval($options['publish'])) {
                static::callPublish();
            }
        } finally {
            if (boolval($options['maintenance'])) {
                \Artisan::call('up');
            }
        }
    }


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
     * Update exment(and laravel-admin)
     *
     * @return void
     */
    public static function updateExment(ScriptEvent $event = null)
    {
        if (!$event) {
            $composer = new Composer();
            $baseDir = base_path();

            if (file_exists("$baseDir/autoload.php")) {
                $baseDir .= '/..';
            }

            $config = new Config(true, $baseDir);
            $config->merge(['config' => ['archive-dir' => $baseDir]]);

            $composer->setConfig($config);
            $event = new ScriptEvent(
                'upgrade-carbon',
                $composer,
                new NullIO()
            );
        }

        $helper = new UpdateHelper($event);

        $upgrades = array(
            Define::COMPOSER_PACKAGE_NAME_LARAVEL_ADMIN => '*',
            Define::COMPOSER_PACKAGE_NAME => '*',
        );
        $helper->setDependencyVersions($upgrades)->require($upgrades);
    }


    public static function callPublish()
    {
        \Artisan::call('exment:update');
    }
}
