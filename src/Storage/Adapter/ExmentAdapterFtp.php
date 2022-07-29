<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;

class ExmentAdapterFtp extends FtpAdapter implements ExmentAdapterInterface
{
    use AdapterTrait;

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeConfig = static::getConfig($config);

        $driver = new self(FtpConnectionOptions::fromArray($mergeConfig));
        return $driver;
    }

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [
            'root' => config('exment.rootpath.ftp.' . $mergeFrom),
        ];
    }

    /**
     * Get config. Execute merge.
     *
     * @param array $config
     * @return array
     */
    public static function getConfig($config): array
    {
        $mergeFrom = array_get($config, 'mergeFrom');
        $mergeConfig = static::mergeFileConfig('filesystems.disks.ftp', "filesystems.disks.$mergeFrom", $mergeFrom);
        $mergeConfig['driver'] = 'ftp';
        return $mergeConfig;
    }
}
