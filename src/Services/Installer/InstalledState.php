<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Services\EnvService;

/**
 * Whether this Exment is installed, decided without the database and without
 * the config cache, so that a site stays "installed" while its database cannot
 * be reached.
 *
 * Installed when one of these is there:
 * - the marker file (storage/app/.exment_installed), written at the end of the
 *   install, of the first setup, of "exment:update", and on any request while
 *   the database says the site is set up;
 * - EXMENT_INITIALIZE in the .env file (read from the file itself: with
 *   "php artisan config:cache", env() does not read .env), or in the real
 *   environment of the process. Sites set up before the marker existed have it.
 *
 * To run the web installer again on purpose, remove both.
 */
class InstalledState
{
    /** The marker file, under storage_path(). */
    public const MARKER = 'app/.exment_installed';

    /** @var string|null for tests: the marker file to look at (null: the real one) */
    public static $markerPath = null;

    /** @var string|null for tests: the .env file to look at (null: the real one) */
    public static $envPath = null;

    public static function isInstalled(): bool
    {
        return is_file(static::markerPath()) || static::envSaysInitialized();
    }

    public static function markerPath(): string
    {
        return static::$markerPath ?? storage_path(static::MARKER);
    }

    /**
     * Write the marker, if it is not there yet. A failure stops nothing.
     */
    public static function markInstalled(): void
    {
        try {
            $path = static::markerPath();
            if (is_file($path)) {
                return;
            }
            $dir = dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0755, true);
            }
            @file_put_contents($path, "Exment is installed. The web installer is closed while this file or EXMENT_INITIALIZE in .env is there.\n", LOCK_EX);
        } catch (\Throwable $ex) {
        }
    }

    /**
     * EXMENT_INITIALIZE is on in the environment of the process or in the .env file.
     */
    public static function envSaysInitialized(): bool
    {
        if (static::isOn(getenv('EXMENT_INITIALIZE'))) {
            return true;
        }

        foreach (static::envPaths() as $path) {
            try {
                $lines = EnvService::getEnv('EXMENT_INITIALIZE', $path);
            } catch (\Throwable $ex) {
                continue;
            }
            if (!empty($lines) && static::isOn($lines[0][1] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return string[]
     */
    protected static function envPaths(): array
    {
        if (!is_null(static::$envPath)) {
            return [static::$envPath];
        }
        $paths = [base_path('.env')];
        try {
            $paths[] = app()->environmentFilePath();
        } catch (\Throwable $ex) {
        }
        return array_values(array_unique(array_filter($paths)));
    }

    /**
     * @param mixed $value
     */
    protected static function isOn($value): bool
    {
        if (!is_string($value) && !is_int($value) && !is_bool($value)) {
            return false;
        }
        $value = trim(strval($value));
        // an unquoted value ends at " #" (a comment)
        if (!preg_match('/^["\']/', $value) && ($pos = strpos($value, ' #')) !== false) {
            $value = substr($value, 0, $pos);
        }
        $value = trim($value, " \t\"'");
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Stop here (404) when the site is installed: the installer must not run
     * again, nor write .env.
     */
    public static function refuseIfInstalled(): void
    {
        if (static::isInstalled()) {
            abort(404);
        }
    }
}
