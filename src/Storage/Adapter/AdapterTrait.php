<?php

namespace Exceedone\Exment\Storage\Adapter;

/**
 *
 * @method static getMergeConfigKeys(string $mergeFrom, array $options = []);
 */
trait AdapterTrait
{
    /**
     * Merge config
     *
     * @param [type] $configKey
     * @param [type] $driver
     * @return array
     */
    public static function mergeFileConfig($baseConfigKey, $mergeConfigKey, $mergeFrom)
    {
        $baseConfig = config($baseConfigKey, []);
        $mergeConfig = config($mergeConfigKey, []);

        if (array_get($mergeConfig, 'driver') != 'local') {
            array_forget($mergeConfig, ['root']);
        }
        array_forget($mergeConfig, ['driver']);

        $driver = array_get($baseConfig, 'driver');

        foreach ($mergeConfig as $k => $m) {
            array_set($baseConfig, $k, $m);
        }

        $keys = static::getMergeConfigKeys($mergeFrom);
        foreach ($keys as $k => $v) {
            if (is_nullorempty($v)) {
                continue;
            }
            array_set($baseConfig, $k, $v);
        }

        return $baseConfig;
    }
}
