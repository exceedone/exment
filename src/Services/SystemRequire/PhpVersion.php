<?php

namespace Exceedone\Exment\Services\SystemRequire;

use Exceedone\Exment\Enums\SystemRequireResult;

/**
 * The version of PHP against the one the installed packages need: the platform check
 * composer wrote (vendor/composer/platform_check.php), else "php" of composer.json.
 * The web and the commands (cron, php artisan) may use different PHPs (a rental server):
 * the check is shown for the PHP that runs it, with the version needed.
 */
class PhpVersion extends SystemRequireBase
{
    /** @var string|null the version of PHP (tests) */
    public static $version;

    /** @var string|null the base folder of the site (tests) */
    public static $basePath;

    public function getLabel(): string
    {
        return exmtrans('system_require.type.php_version.label');
    }

    public function getExplain(): string
    {
        return exmtrans('system_require.type.php_version.explain');
    }

    public static function current(): string
    {
        return strval(static::$version ?? PHP_VERSION);
    }

    /**
     * The lowest version the site can run with ("8.3.0").
     */
    public static function minimum(): string
    {
        $base = static::$basePath ?? base_path();
        $check = @file_get_contents($base . '/vendor/composer/platform_check.php');
        if (is_string($check) && preg_match('/PHP_VERSION_ID\s*>=\s*(\d{5,6})/', $check, $m)) {
            $id = intval($m[1]);
            return intdiv($id, 10000) . '.' . intdiv($id % 10000, 100) . '.' . ($id % 100);
        }
        $composer = json_decode(strval(@file_get_contents($base . '/composer.json')), true);
        $php = is_array($composer) ? strval(data_get($composer, 'config.platform.php') ?: data_get($composer, 'require.php', '')) : '';
        if (preg_match('/(\d+\.\d+(\.\d+)?)/', $php, $m)) {
            return count(explode('.', $m[1])) === 2 ? $m[1] . '.0' : $m[1];
        }
        return '8.3.0';
    }

    public function getResultText(): ?string
    {
        return exmtrans('system_require.type.php_version.result', ['version' => static::current(), 'minimum' => static::minimum()]);
    }

    public function checkResult(): string
    {
        return version_compare(static::current(), static::minimum(), '>=') ? SystemRequireResult::OK : SystemRequireResult::NG;
    }

    protected function getMessageNg(): ?string
    {
        return exmtrans('system_require.type.php_version.ng', ['minimum' => static::minimum()]);
    }

    public function getSettingUrl(): ?string
    {
        return null;
    }
}
