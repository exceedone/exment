<?php

namespace Exceedone\Exment\Storage\Adapter;

interface ExmentAdapterInterface
{
    // @phpstan-ignore-next-line
    public function getUrl($path);

    /**
     * get adapter class
     */
    // @phpstan-ignore-next-line
    public static function getAdapter($app, $config, $driverKey);

    // @phpstan-ignore-next-line
    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array;

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    // @phpstan-ignore-next-line
    public static function getConfig($config): array;
}
