<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * "composer install --optimize-autoloader --no-dev" writes a map of every class,
 * so PHP finds a class without looking through the folders.
 */
class ComposerAutoloadTuning extends TuningBase
{
    /** An optimized map of Exment and its packages has tens of thousands of classes. */
    public const MIN_CLASSES = 5000;

    protected function key(): string
    {
        return 'composer_autoload';
    }

    public function checkResult(): string
    {
        $path = base_path('vendor/composer/autoload_classmap.php');
        if (!is_file($path)) {
            return SystemRequireResult::WARNING;
        }
        $map = include $path;
        return is_array($map) && count($map) >= static::MIN_CLASSES ? SystemRequireResult::OK : SystemRequireResult::WARNING;
    }

    public function getFixText(): ?string
    {
        return 'composer install --optimize-autoloader --no-dev';
    }
}
