<?php

namespace Exceedone\Exment\Storage\Adapter;

use League\Flysystem\Local\LocalFilesystemAdapter;

class ExmentAdapterLocal extends LocalFilesystemAdapter implements ExmentAdapterInterface
{
    use AdapterTrait;

    /**
     * @var array
     */
    protected static $permissions = [
        'file' => [
            'public' => 0644,
            'private' => 0600,
        ],
        'dir' => [
            // Change public permission 0755 to 0775
            'public' => 0775,
            'private' => 0700,
        ],
    ];

    /**
     * Get tenant prefix for storage paths
     *
     * @return string
     */
    protected static function getTenantPrefix(): string
    {
        $tenantInfo = tenant();
        $tenantSuuid = $tenantInfo['tenant_suuid'] ?? null;
        $tenantId = $tenantInfo['id'] ?? null;
        $prefix = $tenantSuuid ? "tenant_{$tenantId}_{$tenantSuuid}/" : "";
        
        return $prefix;
    }

    /**
     * get adapter class
     */
    public static function getAdapter($app, $config, $driverKey)
    {
        $mergeConfig = static::getConfig($config);
        
        $prefix = static::getTenantPrefix();
        $rootPath = array_get($mergeConfig, 'root');
        $fullPath = $prefix ? $rootPath . '/' . trim($prefix, '/') : $rootPath;
        
        return new self($fullPath);
    }

    public static function getMergeConfigKeys(string $mergeFrom, array $options = []): array
    {
        return [];
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
        $config = static::mergeFileConfig('filesystems.disks.local', "filesystems.disks.$mergeFrom", $mergeFrom);
        return $config;
    }
}
