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
        $options = array_merge([
            'backup' => true,
            'publish' => true,
        ], $options);

        if(boolval($options['backup'])){
            static::callBackup();
        }
        
        static::updateExment();
        
        if(boolval($options['publish'])){
            static::callPublish();
        }
    }


    public static function callBackup()
    {
        // check backup execute
        try {
            $backup = new Backup;
            $backup->check();
            $backup->execute();
        } catch (BackupRestoreNotSupportedException $ex) {
        } catch (BackupRestoreCheckException $ex) {
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
            $baseDir = __DIR__.'/../..';

            if (file_exists("$baseDir/autoload.php")) {
                $baseDir .= '/..';
            }

            $composer->setConfig(new Config(true, $baseDir));
            $event = new ScriptEvent(
                'upgrade-carbon',
                $composer,
                new NullIO
            );
        }

        $helper = new UpdateHelper($event);

        $upgrades = array(
            Define::COMPOSER_PACKAGE_NAME_LARAVEL_ADMIN => '*',
            Define::COMPOSER_PACKAGE_NAME => '*',
        );

        $helper->setDependencyVersions($upgrades)->require($upgrades);
    }


    public static function callPublish(){
        \Artisan::call('exment:update');
    }
}
