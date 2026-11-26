<?php

namespace Exceedone\Exment\Services\Sync;

/**
 * The connectors of the data sync (ISS00113): a plugin registers its own (in its constructor or
 * its service provider), as a feature of a plugin is registered.
 *
 *   SyncConnectors::register(KintoneConnector::class);
 */
class SyncConnectors
{
    /** @var array<string, class-string<SyncConnector>|SyncConnector> */
    protected static $connectors = [];

    /** @var bool the plugins were asked once */
    protected static $booted = false;

    /**
     * Build the classes of the page plugins once, so each registers its connector in its
     * constructor: also where Exment does not build them by itself (the routes cached, the queue
     * worker, the commands).
     */
    public static function boot(): void
    {
        if (static::$booted) {
            return;
        }
        static::$booted = true;
        try {
            if (canConnection() && hasTable(\Exceedone\Exment\Enums\SystemTableName::PLUGIN)) {
                \Exceedone\Exment\Model\Plugin::getByPluginTypes(\Exceedone\Exment\Enums\PluginType::PAGE, true);
            }
        } catch (\Throwable $e) {
            \Log::warning('[Exment] sync: the plugins could not be asked for their connectors: ' . get_class($e));
        }
    }

    /**
     * @param class-string<SyncConnector>|SyncConnector $connector
     */
    public static function register($connector): void
    {
        $instance = is_string($connector) ? app($connector) : $connector;
        if (!$instance instanceof SyncConnector || preg_match('/^[a-z0-9_]{1,40}$/', $instance->key()) !== 1) {
            return;
        }
        static::$connectors[$instance->key()] = $instance;
    }

    public static function get(?string $key): ?SyncConnector
    {
        static::boot();
        $connector = isset($key) ? (static::$connectors[$key] ?? null) : null;
        return $connector instanceof SyncConnector ? $connector : null;
    }

    /**
     * @return array<string, string> key => label
     */
    public static function options(): array
    {
        static::boot();
        $options = [];
        foreach (static::$connectors as $key => $connector) {
            if ($connector instanceof SyncConnector) {
                $options[$key] = $connector->label();
            }
        }
        return $options;
    }

    public static function forget(string $key): void
    {
        unset(static::$connectors[$key]);
    }
}
