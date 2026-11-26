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
        $connector = isset($key) ? (static::$connectors[$key] ?? null) : null;
        return $connector instanceof SyncConnector ? $connector : null;
    }

    /**
     * @return array<string, string> key => label
     */
    public static function options(): array
    {
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
