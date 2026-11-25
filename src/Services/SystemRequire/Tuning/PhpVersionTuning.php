<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * Newer PHP is faster, and PHP 8.2 gets security fixes only until 2026-12-31.
 */
class PhpVersionTuning extends TuningBase
{
    public const RECOMMENDED = '8.3.0';

    protected function key(): string
    {
        return 'php_version';
    }

    public function checkResult(): string
    {
        return version_compare(PHP_VERSION, static::RECOMMENDED, '>=') ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getResultText(): ?string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }
}
