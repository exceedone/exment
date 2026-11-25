<?php

namespace Exceedone\Exment\Services;

/**
 * The settings the operator of the server decides (ISS02861), read from files only root can
 * write, over the config of the site: the .env of a site (and so the config made from it) can be
 * changed by the site itself (the tenant settings of the SaaS build, a restore, a plugin); these
 * files cannot.
 *
 * The files, each optional, read in this order (a later one wins):
 * - /etc/exment/exment.php: the whole server
 * - /etc/exment/sites/<the name of the folder of the site>.php: one site (a tenant of the SaaS
 *   build is the folder tenantN)
 * - the paths an application adds (CoreExtensions::addOperatorConfigPath)
 * A file returns an array: config key => value, e.g.
 *   return ['exment.plugin_upload_enabled' => false, 'exment.features_locked' => 'tuning'];
 *
 * A file is read only when it and its folder belong to root and nobody else may write them (on a
 * server without owners, Windows: when the PHP user may not write it). Whoever could change it
 * could change these settings too. A file not read is said in the log.
 *
 * The paths are fixed (not from .env, which the site can write). Applied when Exment registers and
 * again when it boots; config:cache makes no difference (it is applied over the cached config).
 */
class OperatorConfig
{
    public const SERVER_FILE = '/etc/exment/exment.php';

    public const SITES_DIR = '/etc/exment/sites';

    /** @var array<string, mixed> the keys and values applied */
    protected static $applied = [];

    /** @var bool tests: read a file the PHP user may write */
    public static $trustWritable = false;

    /**
     * The files read, in order.
     *
     * @return array<int, string>
     */
    public static function paths(): array
    {
        $site = preg_replace('/[^A-Za-z0-9._-]/', '', basename(base_path())) ?: 'exment';
        return array_merge([static::SERVER_FILE, static::SITES_DIR . '/' . $site . '.php'], CoreExtensions::operatorConfigPaths());
    }

    /**
     * Apply the files over the config. Returns the keys applied.
     *
     * @return array<int, string>
     */
    public static function apply(): array
    {
        $values = [];
        foreach (static::paths() as $path) {
            foreach (static::read($path) as $key => $value) {
                $values[$key] = $value;
            }
        }
        if (!empty($values)) {
            config($values);
        }
        static::$applied = $values;
        return array_keys($values);
    }

    /**
     * The config keys the operator decides (as applied).
     *
     * @return array<int, string>
     */
    public static function lockedKeys(): array
    {
        return array_keys(static::$applied);
    }

    public static function locks(string $configKey): bool
    {
        return array_key_exists($configKey, static::$applied);
    }

    /** Forget what was applied (tests). */
    public static function reset(): void
    {
        static::$applied = [];
        static::$trustWritable = false;
    }

    /**
     * Whether only root may change the file: it and its folder belong to root, without the write
     * permission of the group and the others.
     */
    public static function trusted(string $path): bool
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return !is_writable($path) && !is_writable(dirname($path));
        }
        clearstatcache(true, $path);
        foreach ([$path, dirname($path)] as $item) {
            $owner = @fileowner($item);
            $perms = @fileperms($item);
            if ($owner !== 0 || $perms === false || ($perms & 0022) !== 0) {
                return false;
            }
        }
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    protected static function read(string $path): array
    {
        if (!@is_file($path) || !@is_readable($path)) {
            return [];
        }
        if (!static::$trustWritable && !static::trusted($path)) {
            \Log::warning('Exment: ' . $path . ' is not read: it must belong to root, and nobody else may write it or its folder.');
            return [];
        }
        try {
            $values = (static function (string $__path) {
                return include $__path;
            })($path);
        } catch (\Throwable $e) {
            \Log::warning('Exment: ' . $path . ' could not be read: ' . get_class($e));
            return [];
        }
        if (!is_array($values)) {
            return [];
        }
        $result = [];
        foreach ($values as $key => $value) {
            // config keys only ("exment.plugin_upload_enabled"); nothing of the operator file itself
            if (is_string($key) && preg_match('/^[A-Za-z0-9_\-]+(\.[A-Za-z0-9_\-]+)+$/', $key) === 1) {
                $result[$key] = $value;
            }
        }
        return $result;
    }
}
