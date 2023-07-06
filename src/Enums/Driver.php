<?php

namespace Exceedone\Exment\Enums;

use Illuminate\Filesystem\FilesystemAdapter;
use League\Flysystem\Filesystem;
use Exceedone\Exment\Storage\Adapter;

class Driver extends EnumBase
{
    public const LOCAL = 'local';
    public const S3 = 's3';
    public const AZURE = 'azure';
    public const FTP = 'ftp';
    public const SFTP = 'sftp';

    public static $customDrivers = [];


    /**
     * Register custom field.
     *
     * @param string $abstract
     * @param string $class
     *
     * @return void
     */
    public static function extend($abstract, $class)
    {
        static::$customDrivers[$abstract] = $class;
    }


    /**
     * Get Exment Driver
     *
     * @param mixed $app
     * @param array $config
     * @param string $driverKey
     * @return Filesystem|FilesystemAdapter
     */
    public static function getExmentDriver($app, $config, $driverKey)
    {
        $c = config("exment.driver.$driverKey", 'local');
        // if exists extends
        if (array_key_exists($c, static::$customDrivers)) {
            $classname = static::$customDrivers[$c];
        } else {
            $classname = Adapter\ExmentAdapterLocal::class;
            switch ($c) {
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
        }
        $adaper = $classname::getAdapter($app, $config, $driverKey);

        // Merge config
        $config = $classname::getConfig($config);
        $filesystem = new Filesystem($adaper, $config);
        return new FilesystemAdapter($filesystem, $adaper, $config);
    }
}
