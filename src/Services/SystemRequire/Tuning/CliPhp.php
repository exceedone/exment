<?php

namespace Exceedone\Exment\Services\SystemRequire\Tuning;

/**
 * The PHP to write in a cron line: the command line PHP of the same version as the PHP
 * running now. The "php" that cron finds may be another version: on a rental server it is
 * often an older one (Xserver: PHP 8.0 for cron, the version of the server panel for the
 * site), and Exment does not start with it.
 *
 * On the web (php-fpm, php-cgi), the command line PHP is looked for next to it
 * (/opt/php-8.3.37/sbin/php-fpm -> /opt/php-8.3.37/bin/php, /usr/sbin/php-fpm8.3 ->
 * /usr/bin/php8.3). When it is not found: "php".
 */
class CliPhp
{
    /** @var string|null the PHP running now (tests) */
    public static $binary;

    /** @var string|null PHP_SAPI (tests) */
    public static $sapi;

    public static function path(): string
    {
        $binary = strval(static::$binary ?? PHP_BINARY);
        $sapi = strval(static::$sapi ?? PHP_SAPI);
        if ($binary === '') {
            return 'php';
        }
        if ($sapi === 'cli') {
            return static::quote($binary);
        }

        $dir = dirname($binary);
        $name = basename($binary);
        $ext = str_ends_with(strtolower($name), '.exe') ? '.exe' : '';
        $version = preg_match('/(\d+\.\d+)/', $name, $match) ? $match[1] : '';
        $candidates = [];
        foreach (array_unique([$version, '']) as $suffix) {
            $candidates[] = $dir . DIRECTORY_SEPARATOR . 'php' . $suffix . $ext;
            $candidates[] = dirname($dir) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php' . $suffix . $ext;
        }
        foreach ($candidates as $candidate) {
            // open_basedir may keep it from being seen: then "php"
            if (@is_file($candidate) && @is_executable($candidate)) {
                return static::quote($candidate);
            }
        }
        return 'php';
    }

    /**
     * The version of the PHP running now (8.3), for the comment of a cron line.
     */
    public static function version(): string
    {
        return PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
    }

    protected static function quote(string $path): string
    {
        return preg_match('/^[A-Za-z0-9_\/.:\\\\-]+$/', $path) ? $path : escapeshellarg($path);
    }
}
