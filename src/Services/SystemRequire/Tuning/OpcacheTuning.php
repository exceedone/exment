<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * OPcache keeps compiled PHP in memory. Exment and its packages are many files,
 * so the number of files and the memory must be large enough to hold them all.
 * (The command line has its own switch, opcache.enable_cli, which does not matter for the site.)
 */
class OpcacheTuning extends TuningBase
{
    public const MIN_MEMORY = 128;
    public const MIN_FILES = 20000;

    protected function key(): string
    {
        return 'opcache';
    }

    public function checkResult(): string
    {
        if (!extension_loaded('Zend OPcache') || !boolval(ini_get('opcache.enable'))) {
            return SystemRequireResult::WARNING;
        }
        if (intval(ini_get('opcache.memory_consumption')) < static::MIN_MEMORY
            || intval(ini_get('opcache.max_accelerated_files')) < static::MIN_FILES) {
            return SystemRequireResult::WARNING;
        }
        return SystemRequireResult::OK;
    }

    public function getFixText(): ?string
    {
        return implode("\n", [
            '; php.ini',
            'opcache.enable=1',
            'opcache.memory_consumption=256',
            'opcache.interned_strings_buffer=16',
            'opcache.max_accelerated_files=' . static::MIN_FILES,
            'opcache.revalidate_freq=60',
        ]);
    }
}
