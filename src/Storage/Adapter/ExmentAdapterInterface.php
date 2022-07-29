<?php

namespace Exceedone\Exment\Storage\Adapter;

interface ExmentAdapterInterface
{
    public function getUrl($path);

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey);

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array;

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    public static function getConfig($config): array;
}
