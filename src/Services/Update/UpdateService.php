<?php
namespace Exceedone\Exment\Services\Update;

use Composer\Composer;
use Composer\Config;
use Composer\IO\NullIO;
use Composer\Script\Event as ScriptEvent;
use Exceedone\Exment\Model\Define;

/**
 * System update service
 */
class UpdateService
{
    public static function update(array $options = [])
    {

    }


    /**
     * Update exment
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
}
