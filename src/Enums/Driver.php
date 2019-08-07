<?php

namespace Exceedone\Exment\Enums;

use League\Flysystem\Filesystem;
use Exceedone\Exment\Adapter;

class Driver extends EnumBase
{
    public const LOCAL = 'local';
    public const S3 = 's3';
    public const AZURE = 'azure';

    /**
     * Get Exment Driver
     *
     * @param [type] $app
     * @param [type] $config
     * @return void
     */
    public static function getExmentDriver($app, $config){
        switch (config('exment.driver.default', 'local')) {
            case 'local':
                $adaper = Adapter\ExmentAdapterLocal::getAdapter($app, $config);
                break;
            case 's3':
                $adaper = Adapter\ExmentAdapterS3::getAdapter($app, $config);
                break;
            case 'azure':
                $adaper = Adapter\ExmentAdapterAzure::getAdapter($app, $config);
                break;
            default:
                $adaper = Adapter\ExmentAdapterLocal::getAdapter($app, $config);
                break;
        }
        return new Filesystem($adaper);
    }
}
