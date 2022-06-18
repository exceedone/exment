<?php

namespace Exceedone\Exment\Storage\Adapter;

use Exceedone\Exment\Model\File;
use League\Flysystem\PathPrefixer;

/**
 *
 * @method static getMergeConfigKeys(string $mergeFrom, array $options = []);
 */
trait AdapterTrait
{
    /**
     * Get URL using File class
     */
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


    /**
     * Get PathPrefixer.
     * $prefixer is private, so we have to get this property forcibly.
     *
     * @return PathPrefixer
     */
    protected function getPrefixer() : PathPrefixer
    {
        $reflectionClass = new \ReflectionClass(get_class($this));
        return $reflectionClass->getProperty('prefixer');
    }
    
    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix(string $path) : string{
        return $this->getPrefixer()->prefixPath($path);
    }
}
