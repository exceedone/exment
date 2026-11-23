<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Services\Installer\InstalledState;

/**
 * Points InstalledState at a marker file and a .env file of a temporary folder,
 * and takes EXMENT_INITIALIZE out of the environment of the process, as a
 * config cache does (.env is then not read into the environment).
 */
trait InstalledStateTrait
{
    /** @var string|null */
    private $installedStateDir;

    /** @var string|false */
    private $installedStateEnvValue = false;

    /**
     * @param bool $marker the marker file is there
     * @param string|null $envContent the content of the .env file (null: no .env file)
     */
    protected function useInstalledState(bool $marker, ?string $envContent = null): void
    {
        $this->installedStateDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . uniqid('exment_installed_', true);
        mkdir($this->installedStateDir);

        InstalledState::$markerPath = $this->installedStateDir . DIRECTORY_SEPARATOR . '.exment_installed';
        InstalledState::$envPath = $this->installedStateDir . DIRECTORY_SEPARATOR . '.env';
        if ($marker) {
            file_put_contents(InstalledState::$markerPath, 'test');
        }
        if (!is_null($envContent)) {
            file_put_contents(InstalledState::$envPath, $envContent);
        }

        $this->installedStateEnvValue = getenv('EXMENT_INITIALIZE');
        putenv('EXMENT_INITIALIZE');
    }

    protected function restoreInstalledState(): void
    {
        if (!is_null($this->installedStateDir) && is_dir($this->installedStateDir)) {
            foreach ((array) glob($this->installedStateDir . DIRECTORY_SEPARATOR . '{,.}*', GLOB_BRACE) as $file) {
                if (is_string($file) && is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($this->installedStateDir);
        }
        $this->installedStateDir = null;
        InstalledState::$markerPath = null;
        InstalledState::$envPath = null;

        if ($this->installedStateEnvValue === false) {
            putenv('EXMENT_INITIALIZE');
        } else {
            putenv('EXMENT_INITIALIZE=' . $this->installedStateEnvValue);
        }
    }
}
