<?php

namespace Exceedone\Exment\Services;

/**
 * The versions shown in the system setting ("バージョン情報", ISS02828): Exment, and
 * PHP, the web server, the database and the OS, with a text to paste into an inquiry.
 *
 * A server that keeps Tuning from its sites (EXMENT_FEATURES_LOCKED=tuning, a SaaS)
 * shows only the Exment version: the others would help an attacker, and the tenants
 * cannot change the server anyway.
 */
class SystemInfo
{
    /**
     * Whether the versions of PHP, the web server, the database and the OS are shown.
     */
    public static function showsServer(): bool
    {
        return !Features::locked(Features::TUNING);
    }

    /**
     * The rows: label => value.
     *
     * @return array<string, string>
     */
    public static function items(): array
    {
        list(, $current) = \Exceedone\Exment\Facades\ExmentFacade::getExmentVersion();
        $items = [
            'Exment' => static::orUnknown($current),
        ];
        if (!static::showsServer()) {
            return $items;
        }

        $items['PHP'] = PHP_VERSION;
        $items[exmtrans('system.version_info.web_server')] = static::webServer();
        $items[exmtrans('system.version_info.database')] = static::database();
        $items['OS'] = static::orUnknown(trim(php_uname('s') . ' ' . php_uname('r')));
        return $items;
    }

    /**
     * The rows as text, one "label: value" a line (the copy button).
     */
    public static function text(): string
    {
        return collect(static::items())->map(function ($value, $label) {
            return $label . ': ' . $value;
        })->implode("\n");
    }

    /**
     * SERVER_SOFTWARE of the web server (Apache, nginx), or "unknown".
     */
    public static function webServer(): string
    {
        $value = request()->server('SERVER_SOFTWARE');
        return static::orUnknown(is_string($value) ? $value : null);
    }

    /**
     * The driver and the version of the database server, or "unknown".
     */
    public static function database(): string
    {
        try {
            $connection = \Illuminate\Support\Facades\DB::connection();
            $version = $connection->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            return trim($connection->getDriverName() . ' ' . (is_scalar($version) ? strval($version) : ''));
        } catch (\Throwable $ex) {
            return exmtrans('system.version_info.unknown');
        }
    }

    protected static function orUnknown(?string $value): string
    {
        return is_null($value) || trim($value) === '' ? exmtrans('system.version_info.unknown') : $value;
    }
}
