<?php

namespace Exceedone\Exment\Services\SystemRequire\Security;

use Exceedone\Exment\Enums\SystemRequireResult;
use Exceedone\Exment\Model\Define;

/**
 * The versions of PHP and of the database that still get fixes (ISS00171, ISS00216).
 *
 * - PHP: its security support ends at the end of a year (php.net "Supported Versions"). Ended: NG.
 *   Ending in the next WARN_DAYS days: a warning (PHP 8.2: 2026-12-31).
 * - The database: older than the recommended version (Define::DATABASE_VERSION "recommended":
 *   MySQL 8.0, MariaDB 10.11, SQL Server 2016 = 13.0) is a warning. Exment still runs on it down to
 *   the "min" of the install; the versions older than the recommended ones get no fixes from their
 *   makers. Without a database (the install screen before the database step) only PHP is asked.
 *
 * Nothing is stopped: it is said on the security check, at the setup and by exment:update.
 */
class SupportedVersionSecurity extends SecurityBase
{
    /** The last day of the security support of each PHP version (php.net/supported-versions). */
    public const PHP_SECURITY_SUPPORT = [
        '7.4' => '2022-11-28',
        '8.0' => '2023-11-26',
        '8.1' => '2025-12-31',
        '8.2' => '2026-12-31',
        '8.3' => '2027-12-31',
        '8.4' => '2028-12-31',
        '8.5' => '2029-12-31',
    ];

    /** The days before the end of the support of PHP that it is said. */
    public const WARN_DAYS = 120;

    /** @var string|null "major.minor" of PHP (tests) */
    public static $phpVersion;

    /** @var string|null today, Y-m-d (tests) */
    public static $today;

    /** @var array{type: string, version: string}|false|null the database (tests; false: none) */
    public static $database;

    protected function key(): string
    {
        return 'supported_versions';
    }

    public static function phpVersion(): string
    {
        return static::$phpVersion ?? (PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION);
    }

    protected static function today(): string
    {
        return static::$today ?? date('Y-m-d');
    }

    /** The last day of the security support of this PHP, or null when it is not known. */
    public static function phpSupportEnds(): ?string
    {
        return static::PHP_SECURITY_SUPPORT[static::phpVersion()] ?? null;
    }

    public static function phpSupportEnded(): bool
    {
        $ends = static::phpSupportEnds();
        return isset($ends) && static::today() > $ends;
    }

    public static function phpSupportEnding(): bool
    {
        $ends = static::phpSupportEnds();
        if (!isset($ends) || static::phpSupportEnded()) {
            return false;
        }
        return (strtotime($ends) - strtotime(static::today())) <= static::WARN_DAYS * 86400;
    }

    /**
     * The type (mysql, mariadb, sqlsrv) and the version of the database, or null without one.
     *
     * @return array{type: string, version: string}|null
     */
    public static function database(): ?array
    {
        if (isset(static::$database)) {
            return static::$database === false ? null : static::$database;
        }
        try {
            if (!canConnection()) {
                return null;
            }
            $connection = \DB::connection();
            $driver = strval($connection->getDriverName());
            if ($driver === 'sqlite') {
                return null;
            }
            $type = $driver === 'sqlsrv' ? 'sqlsrv' : (method_exists($connection, 'isMariaDB') && $connection->isMariaDB() ? 'mariadb' : 'mysql');
            $version = method_exists($connection, 'getVersion') ? strval($connection->getVersion()) : '';
            if ($version === '') {
                return null;
            }
            return ['type' => $type, 'version' => $version];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** The recommended version of the database, or null when it is not known. */
    public static function databaseRecommended(): ?string
    {
        $database = static::database();
        return isset($database) ? (Define::DATABASE_VERSION[$database['type']]['recommended'] ?? null) : null;
    }

    public static function databaseOld(): bool
    {
        $database = static::database();
        $recommended = static::databaseRecommended();
        if (!isset($database, $recommended)) {
            return false;
        }
        // "10.6.12-MariaDB-log": the numbers in front
        $version = preg_match('/^\d+(\.\d+)*/', $database['version'], $m) ? $m[0] : $database['version'];
        return version_compare($version, $recommended, '<');
    }

    public function checkResult(): string
    {
        if (static::phpSupportEnded()) {
            return SystemRequireResult::NG;
        }
        return static::phpSupportEnding() || static::databaseOld() ? SystemRequireResult::WARNING : SystemRequireResult::OK;
    }

    public function getResultText(): ?string
    {
        $items = ['PHP ' . static::phpVersion() . (static::phpSupportEnds() ? ' (' . exmtrans('security_check.item.supported_versions.php_until', ['date' => static::phpSupportEnds()]) . ')' : '')];
        $database = static::database();
        if (isset($database)) {
            $items[] = (Define::DATABASE_TYPE[$database['type']] ?? $database['type']) . ' ' . $database['version'];
        }
        return implode(', ', $items);
    }

    /**
     * What is wrong, one sentence each.
     *
     * @return array<int, string>
     */
    public function findings(): array
    {
        $found = [];
        if (static::phpSupportEnded()) {
            $found[] = strval(exmtrans('security_check.item.supported_versions.php_ended', ['version' => static::phpVersion(), 'date' => static::phpSupportEnds()]));
        } elseif (static::phpSupportEnding()) {
            $found[] = strval(exmtrans('security_check.item.supported_versions.php_ending', ['version' => static::phpVersion(), 'date' => static::phpSupportEnds()]));
        }
        if (static::databaseOld()) {
            $database = static::database();
            $found[] = strval(exmtrans('security_check.item.supported_versions.database_old', [
                'database' => Define::DATABASE_TYPE[$database['type'] ?? ''] ?? '',
                'version' => $database['version'] ?? '',
                'recommended' => static::databaseRecommended(),
            ]));
        }
        return $found;
    }

    protected function getMessageWarning(): ?string
    {
        return implode(' ', $this->findings());
    }

    public function getFixText(): ?string
    {
        return $this->checkResult() == SystemRequireResult::OK ? null : exmtrans('security_check.item.supported_versions.fix');
    }
}
