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
        // run as the owner of vendor/: another user (www-data on a docker install where vendor/
        // is root's) cannot write the map, and composer may say nothing about it
        $owner = static::vendorOwner();
        return 'cd ' . base_path() . "\n"
            . '# as the owner of vendor/' . (is_null($owner) ? '' : ' (' . $owner . ')') . "\n"
            . 'composer dump-autoload -o';
    }

    /**
     * The name of the owner of vendor/, or null.
     */
    public static function vendorOwner(): ?string
    {
        $path = base_path('vendor');
        if (!is_dir($path) || !function_exists('posix_getpwuid')) {
            return null;
        }
        $uid = @fileowner($path);
        if ($uid === false) {
            return null;
        }
        $info = @posix_getpwuid($uid);
        return is_array($info) ? strval($info['name']) : strval($uid);
    }
}
