<?php

namespace Exceedone\Exment\Services\Migration;

/**
 * Finds and loads a mapping preset.
 *
 * A preset is a plain php file returning an array: which source stream becomes
 * which Exment table, and which field becomes which column. No code, so it can
 * be edited on site by whoever is doing the migration, and diffed afterwards
 * when somebody asks why a field came across the way it did.
 *
 * A copy under storage wins over the one shipped in the package. That ordering
 * is the point: every real migration needs the mapping bent to fit one
 * customer's configuration, and doing that by editing files inside vendor/
 * means the change is gone at the next composer update - along with any record
 * of what it was.
 */
class Preset
{
    /** Where a site keeps its own presets, under storage/app. */
    public const USER_PATH = 'migration/presets';

    /**
     * Directories searched, nearest first.
     *
     * @return string[]
     */
    public static function directories(): array
    {
        $directories = [];

        if ($configured = config('exment.migration_preset_dir')) {
            $directories[] = rtrim(strval($configured), '/\\');
        }

        $directories[] = storage_path(path_join('app', static::USER_PATH));
        $directories[] = __DIR__ . DIRECTORY_SEPARATOR . 'Presets';

        return $directories;
    }

    /**
     * Every preset available, by name.
     *
     * @return array<string, string> name => path
     */
    public static function names(): array
    {
        $found = [];

        foreach (static::directories() as $directory) {
            if (!is_dir($directory)) {
                continue;
            }

            foreach (glob(rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . '*.php') ?: [] as $path) {
                $name = pathinfo($path, PATHINFO_FILENAME);
                // nearest directory wins, so do not let a later one overwrite
                if (!array_key_exists($name, $found)) {
                    $found[$name] = $path;
                }
            }
        }

        ksort($found);

        return $found;
    }

    /**
     * @param string $name
     * @return array<string, mixed>
     * @throws \Exception when there is no such preset, or it is not an array
     */
    public static function load(string $name): array
    {
        $path = array_get(static::names(), $name);

        if (!$path) {
            throw new \Exception(sprintf(
                'no preset called "%s". Available: %s',
                $name,
                implode(', ', array_keys(static::names())) ?: '(none)'
            ));
        }

        $preset = require $path;

        if (!is_array($preset)) {
            throw new \Exception($path . ' did not return an array');
        }

        if (!array_get($preset, 'name')) {
            // the name is what every migration key is prefixed with, so it is
            // not allowed to be implicit
            $preset['name'] = $name;
        }

        return $preset;
    }

    /**
     * Copy a shipped preset into storage so it can be edited safely.
     *
     * @param string $name
     * @return string the new path
     * @throws \Exception
     */
    public static function publish(string $name): string
    {
        $source = array_get(static::names(), $name);
        if (!$source) {
            throw new \Exception('no preset called "' . $name . '"');
        }

        $directory = storage_path(path_join('app', static::USER_PATH));
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new \Exception('could not create ' . $directory);
        }

        $target = $directory . DIRECTORY_SEPARATOR . $name . '.php';

        if (realpath($source) === realpath($target)) {
            return $target;
        }

        if (!copy($source, $target)) {
            throw new \Exception('could not copy the preset to ' . $target);
        }

        return $target;
    }
}
