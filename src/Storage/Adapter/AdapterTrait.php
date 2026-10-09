<?php

namespace Exceedone\Exment\Storage\Adapter;

use Exceedone\Exment\Model\File;

/**
 *
 * @method static getMergeConfigKeys(string $mergeFrom, array $options = []);
 */
trait AdapterTrait
{
    /**
     * Get URL using File class
     */
    // @phpstan-ignore-next-line
    public function getUrl($path)
    {
        return File::getUrl($path);
    }

    /**
     * Merge config
     *
     * @param string $baseConfigKey
     * @param string $mergeConfigKey
     * @param string $mergeFrom
     * @return array<string, mixed>
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

        /** @phpstan-ignore-next-line Static call to instance method */
        $keys = static::getMergeConfigKeys($mergeFrom);
        /** @phpstan-ignore-next-line Dynamic iteration over adapter config keys */
        foreach ($keys as $k => $v) {
            /** @phpstan-ignore-next-line Helper function from Helpers.php */
            if (is_nullorempty($v)) {
                continue;
            }
            array_set($baseConfig, $k, $v);
        }

        return $baseConfig;
    }
}
