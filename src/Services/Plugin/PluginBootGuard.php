<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model\Plugin;

/**
 * The steps that run for every active plugin on each page: the views and the translations at the
 * boot, the routes, the scripts and the styles, the scheduled batches. An error of one plugin in
 * them is logged once and that plugin is left out of the step, so that Exment and the other
 * plugins keep working. Not for the requests of a plugin itself: their errors are shown as before.
 */
class PluginBootGuard
{
    /** a plugin logged is not logged again for this time (seconds) */
    public const LOG_SECONDS = 600;

    /** @var array<string, int> the plugins logged in this process, and when */
    protected static $logged = [];

    /**
     * Run a step for one plugin.
     *
     * @param mixed $plugin the Plugin, the class of the plugin (PluginBase), or null
     * @param string $step what is done, for the log (e.g. "views", "routes")
     * @param \Closure $callback
     * @param mixed $default returned when the step failed
     * @return mixed
     */
    public static function run($plugin, string $step, \Closure $callback, $default = null)
    {
        try {
            return $callback();
        } catch (\Throwable $e) {
            static::report($plugin, $step, $e);
            return $default;
        }
    }

    /**
     * Log that a plugin was left out of a step: once a plugin in a process, and once in
     * LOG_SECONDS while the cache can be used (the first step that failed is logged). No stack
     * trace, so that a broken plugin does not fill the log.
     *
     * @param mixed $plugin the Plugin, the class of the plugin (PluginBase), or null
     * @param string $step
     * @param \Throwable|string $error
     * @return void
     */
    public static function report($plugin, string $step, $error): void
    {
        try {
            $model = static::model($plugin);
            $name = $model->plugin_name ?? (is_object($plugin) ? get_class($plugin) : 'unknown');
            $reason = $error instanceof \Throwable ? get_class($error) . ': ' . $error->getMessage() : strval($error);

            // a long process (a queue worker) logs it again after the time too
            if (isset(static::$logged[$name]) && time() - static::$logged[$name] < static::LOG_SECONDS) {
                return;
            }
            static::$logged[$name] = time();
            try {
                if (!\Cache::add(static::cacheKey($name), 1, static::LOG_SECONDS)) {
                    return;
                }
            } catch (\Throwable $e) {
                // without the cache: once in this process
            }

            $message = "[Exment] plugin {$name}: skipped in this request (failed in: {$step}). {$reason}";
            if (!is_null($hint = static::typesHint($model))) {
                $message .= " ({$hint})";
            }
            \Log::error($message);
        } catch (\Throwable $e) {
            // the log never stops the page
        }
    }

    /**
     * The key of the cache that keeps a plugin from being logged again.
     */
    public static function cacheKey(string $pluginName): string
    {
        return 'exment_plugin_boot_error_' . md5($pluginName);
    }

    /**
     * Log the plugins again (for the tests).
     *
     * @param array<string> $pluginNames whose cache is forgotten too
     */
    public static function forget(array $pluginNames = []): void
    {
        static::$logged = [];
        foreach ($pluginNames as $name) {
            try {
                \Cache::forget(static::cacheKey($name));
            } catch (\Throwable $e) {
            }
        }
    }

    /**
     * When the plugin types of the site differ from the config.json of the plugin's files (the
     * files were replaced by another version without uploading it), what to do.
     *
     * @param Plugin|null $plugin
     * @return string|null
     */
    public static function typesHint(?Plugin $plugin): ?string
    {
        if (is_null($plugin)) {
            return null;
        }
        try {
            $path = $plugin->getFullPath('config.json');
            if (is_null($path) || !\File::exists($path)) {
                return null;
            }
            $json = json_decode_ex(\File::get($path), true);
            if (!is_array($json)) {
                return null;
            }
            $files = static::normalizeTypes(array_get($json, 'plugin_type'));
            $site = static::normalizeTypes($plugin->plugin_types);
            if (empty($files) || static::sorted($files) === static::sorted($site)) {
                return null;
            }

            return "the installed files expect type '" . implode(',', $files) . "' but the site has '" . implode(',', $site)
                . "'; upload the plugin again or update plugin_types";
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The names of the types ("batch", "page"), from the names of config.json or the values of the
     * database ("1,4").
     *
     * @param mixed $types "page,batch", "1,4" or an array
     * @return array<string>
     */
    protected static function normalizeTypes($types): array
    {
        $types = is_array($types) ? $types : explode(',', strval($types));
        $types = array_values(array_unique(array_filter(array_map(function ($type) {
            $type = trim(strval($type));
            $enum = $type === '' ? null : PluginType::getEnum($type);
            return isset($enum) ? $enum->lowerKey() : strtolower($type);
        }, $types), function ($type) {
            return $type !== '';
        })));
        return $types;
    }

    /**
     * @param array<string> $types
     * @return array<string>
     */
    protected static function sorted(array $types): array
    {
        sort($types);
        return $types;
    }

    /**
     * @param mixed $plugin
     * @return Plugin|null
     */
    protected static function model($plugin): ?Plugin
    {
        if ($plugin instanceof Plugin) {
            return $plugin;
        }
        if (!is_object($plugin)) {
            return null;
        }
        if (method_exists($plugin, '_plugin')) {
            $model = $plugin->_plugin();
            return $model instanceof Plugin ? $model : null;
        }

        // a class that is no class of a plugin (e.g. of a version of other types): by its namespace
        if (preg_match('/^(App\\\\Plugins\\\\[^\\\\]+)\\\\/', get_class($plugin), $matches) !== 1) {
            return null;
        }
        $namespace = $matches[1];
        $found = collect(Plugin::allRecordsCache(function ($model) use ($namespace) {
            return $model->getNameSpace() === $namespace;
        }, false))->first();
        return $found instanceof Plugin ? $found : null;
    }
}
