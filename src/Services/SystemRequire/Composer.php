<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\Define;

class Composer extends SystemRequireBase
{
    protected $composer_version;
    protected $command_path;

    /**
     * Warning Type
     * 1: composer file cannot find
     * 2: composer version error
     * 3: composer version < 2.0.0
     *
     * @var int
     */
    protected $warning_type;

    public function __construct()
    {
        // check backup execute
        $result = $this->checkComposerExists();

        if (!isMatchString($result, SystemRequireResult::OK)) {
            $this->result = $result;
            $this->warning_type = 1;
            return;
        }

        $this->composer_version = $this->getComposerVersion();
        $this->result = $this->checkComposerVersion($this->composer_version);
    }

    /**
     * Check composer file exists
     *
     * @return string
     */
    protected function checkComposerExists()
    {
        // check backup execute
        try {
            // check EXMENT_COMPOSER_PATH
            $path = \Exment::getComposerPath();
            if ($path != 'composer') {
                if (file_exists($path)) {
                    $this->command_path = $path;
                    return SystemRequireResult::OK;
                }
                return SystemRequireResult::WARNING;
            }

            $command = \Exment::isWindows() ? 'where composer' : 'which composer';
            foreach (['', '.phar'] as $suffix) {
                exec($command . $suffix, $output, $return_var);
                if ($return_var == 0) {
                    $this->command_path = $path;
                    return SystemRequireResult::OK;
                }
            }

            return SystemRequireResult::WARNING;
        } catch (\Exception $ex) {
            return SystemRequireResult::WARNING;
        }
    }


    /**
     * Get composer version
     *
     * @return false|string
     */
    protected function getComposerVersion()
    {
        $composer_version = \Cache::get(Define::SYSTEM_KEY_SESSION_COMPOSER_VERSION);
        if (is_nullorempty($composer_version)) {
            // check composer version
            exec($this->command_path . ' --version', $output, $return_var);
            if ($return_var != 0) {
                $composer_version = false;
            }
            // get composer version using regex
            else {
                $composer_version_string = $output[0];
                preg_match("/^(Composer version|Composer) (?<version>\d+\.\d+\.\d+)/u", $composer_version_string, $match);
                if ($match) {
                    $composer_version = $match['version'];
                } else {
                    $composer_version = false;
                }
            }
            \Cache::put(Define::SYSTEM_KEY_SESSION_COMPOSER_VERSION, $composer_version, Define::CACHE_CLEAR_MINUTE * 24);
        }
        return $composer_version;
    }


    /**
     * check composer version.
     *
     * @return string
     */
    protected function checkComposerVersion($composer_version)
    {
        if ($composer_version === false) {
            $this->warning_type = 2;
            return SystemRequireResult::WARNING;
        } else {
            $this->composer_version = $composer_version;
            if (version_compare($composer_version, '2.0.0') < 0) {
                $this->warning_type = 3;
                return SystemRequireResult::WARNING;
            }
        }
        return SystemRequireResult::OK;
    }

    public function getLabel(): string
    {
        return exmtrans('system_require.type.composer.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.composer.explain');
    }


    /**
     * Undocumented function
     *
     * @return ?string
     */
    public function getResultText(): ?string
    {
        switch ($this->result) {
            case SystemRequireResult::OK:
                return exmtrans('common.success');

            case SystemRequireResult::WARNING:
                return exmtrans('common.warning');

            case SystemRequireResult::NG:
                return exmtrans('common.error');
        }
        return null;
    }

    /**
     *
     *
     * @return string
     */
    public function checkResult(): string
    {
        return $this->result;
    }

    protected function getMessageWarning(): ?string
    {
        if ($this->warning_type == 1) {
            return exmtrans('system_require.type.composer.warning');
        }
        if ($this->warning_type == 2) {
            return exmtrans('system_require.type.composer.warning_versionget');
        }
        if ($this->warning_type == 3) {
            return exmtrans('system_require.type.composer.warning_versionmin', ['version' => $this->composer_version]);
        }
        return null;
    }

    public function getSettingUrl(): ?string
    {
        return \Exment::getManualUrl('server');
    }
}
