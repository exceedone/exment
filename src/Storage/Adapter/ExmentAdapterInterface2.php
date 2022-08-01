<?php

namespace Exceedone\Exment\Storage\Adapter;

/**
 * ExmentAdapterInterface.
 * *Some adapter "getUrl" appends "string". So please use this.
 */
interface ExmentAdapterInterface2
{
    public function getUrl(string $path): string;

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey);

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    public static function getConfig($config): array;

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array;
}
