<?php

namespace Exceedone\Exment\Enums;

use League\Flysystem\Filesystem;
use Exceedone\Exment\Storage\Adapter;

class Driver extends EnumBase
{
    public const LOCAL = 'local';
    public const S3 = 's3';
    public const AZURE = 'azure';
    public const FTP = 'ftp';
    public const SFTP = 'sftp';

    /**
     * Get Exment Driver
     *
     * @param [type] $app
     * @param [type] $config
     * @return void
     */
    public static function getExmentDriver($app, $config, $driverKey)
    {
        $classname = Adapter\ExmentAdapterLocal::class;
        switch (config("exment.driver.$driverKey", 'local')) {
            case self::LOCAL:
                $classname = Adapter\ExmentAdapterLocal::class;
                break;
            case self::S3:
                $classname = Adapter\ExmentAdapterS3::class;
                break;
            case self::AZURE:
                $classname = Adapter\ExmentAdapterAzure::class;
                break;
            case self::FTP:
                $classname = Adapter\ExmentAdapterFtp::class;
                break;
            case self::SFTP:
                $classname = Adapter\ExmentAdapterSftp::class;
                break;
        }
        $adaper = $classname::getAdapter($app, $config, $driverKey);
        return new Filesystem($adaper);
    }

    /**
     * Merge config
     *
     * @param [type] $configKey
     * @param [type] $driver
     * @return void
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

        ///// merge from env
        $keys = [];
        switch ($driver) {
            case self::LOCAL:
                break;
            case self::S3:
                $keys = [
                    'bucket' => config('exment.rootpath.s3.' . $mergeFrom),
                ];
                break;
            case self::AZURE:
                $keys = [
                    'container' => config('exment.rootpath.azure.' . $mergeFrom),
                ];
                break;
            case self::FTP:
                $keys = [
                    'root' => config('exment.rootpath.ftp.' . $mergeFrom),
                ];
                break;
            case self::SFTP:
                $keys = [
                    'root' => config('exment.rootpath.sftp.' . $mergeFrom),
                ];
                break;
            default:
                break;
        }

        foreach ($keys as $k => $v) {
            if (is_nullorempty($v)) {
                continue;
            }
            array_set($baseConfig, $k, $v);
        }

        return $baseConfig;
    }
}
