<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Tenant;
use Exceedone\Exment\Services\TenantInfoService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Aws\S3\S3Client;
use Aws\Exception\AwsException;

class TenantUsageService
{
    public const CACHE_TIME_SECONDS = 30;
    public const LINK_CREATE_USER = 'data/user/create';
    public const LINK_TENANT_SETTING = 'tenant/settings';
    /**
     * Get usage cache key for tenant total usage
     *
     * @param $subdomain
     * @return string
     */
    public static function getUsageCacheKey($subdomain = null): string
    {
        if (!$subdomain) {
            $subdomain = tenant('subdomain');
        }

        return "tenant_total_usage_bytes_{$subdomain}";
    }

    /**
     * Get current subdomain with cache check and refresh logic
     * If cache with getUsageCacheKey is expired, recalculate using getCombinedUsage and cache for 30 seconds
     *
     * @return array
     */
    public static function getCurrentSubdomainWithUsage($subdomain): array
    {
        try {

            $usageCacheKey = self::getUsageCacheKey($subdomain);
            $cachedUsage = Cache::get($usageCacheKey);

            // If cache is expired or not exists, recalculate
            if ($cachedUsage === null) {
                $combined = self::getCombinedUsage($subdomain);
                if (!($combined['success'] ?? false)) {
                    return [
                        'success' => false,
                        'error' => 'USAGE_CALCULATION_FAILED',
                        'message' => $combined['message'] ?? 'Failed to calculate usage',
                        'status' => 500
                    ];
                }

                $totalBytes = (int) ($combined['data']['total']['total_size_bytes'] ?? 0);
                // Cache for 30 seconds
                Cache::put($usageCacheKey, $totalBytes, TenantUsageService::CACHE_TIME_SECONDS);

                return [
                    'success' => true,
                    'data' => [
                        'subdomain' => $subdomain,
                        'total_usage_bytes' => $totalBytes,
                        'from_cache' => false
                    ]
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'subdomain' => $subdomain,
                    'total_usage_bytes' => (int) $cachedUsage,
                    'from_cache' => true
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get current subdomain with usage', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'GET_SUBDOMAIN_USAGE_FAILED',
                'message' => 'Failed to get current subdomain with usage: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Get database usage from cache for current subdomain
     * If not cached, calculate and set it
     *
     * @param string|null $subdomain
     * @return array
     */
    public static function getDbUsage(?string $subdomain = null): array
    {
        try {
            if (!$subdomain) {
                $subdomain = tenant('subdomain');
            }
            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Current subdomain not found',
                    'status' => 404
                ];
            }

            $cacheKey = "tenant_db_usage_{$subdomain}";
            $cachedUsage = Cache::get($cacheKey);

            if ($cachedUsage === null) {
                $setResult = self::setDbUsage($subdomain);
                if (!$setResult['success']) {
                    return $setResult;
                }
                $cachedUsage = $setResult['data'];
            }

            return [
                'success' => true,
                'data' => $cachedUsage
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get database usage', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'GET_DB_USAGE_FAILED',
                'message' => 'Failed to get database usage: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Set database usage in cache for specific subdomain
     * Calculates the actual database size and caches it
     *
     * @param string|null $subdomain
     * @return array
     */
    public static function setDbUsage(?string $subdomain = null): array
    {
        try {
            if (!$subdomain) {
                $subdomain = tenant('subdomain');
            }

            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Subdomain not provided and current subdomain not found',
                    'status' => 404
                ];
            }

            $dbSize = self::calculateDatabaseSize($subdomain);

            $usageData = [
                'subdomain' => $subdomain,
                'database_size_bytes' => $dbSize,
                'database_size_mb' => round($dbSize / 1024 / 1024, 2),
                'database_size_gb' => round($dbSize / 1024 / 1024 / 1024, 2),
            ];

            return [
                'success' => true,
                'data' => $usageData
            ];
        } catch (\Exception $e) {
            Log::error('Failed to set database usage', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'SET_DB_USAGE_FAILED',
                'message' => 'Failed to set database usage: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Calculate database size for specific subdomain
     *
     * @param string $subdomain
     * @return int Size in bytes
     */
    protected static function calculateDatabaseSize(string $subdomain): int
    {
        try {
            $databaseName = tenant('environment_settings')['db_name'];
            if (!$databaseName) {
                return 0;
            }

            $connection = DB::connection();
            $driver = $connection->getDriverName();

            switch ($driver) {
                case 'mysql':
                case 'mariadb':
                    return self::calculateMysqlDatabaseSize($databaseName);
                case 'pgsql':
                    return self::calculatePostgresDatabaseSize($databaseName);
                case 'sqlsrv':
                    return self::calculateSqlServerDatabaseSize($databaseName);
                default:
                    Log::warning('Unsupported database driver for size calculation', [
                        'driver' => $driver,
                        'subdomain' => $subdomain
                    ]);
                    return 0;
            }
        } catch (\Exception $e) {
            Log::error('Failed to calculate database size', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Calculate MySQL database size
     *
     * @param string $databaseName
     * @return int Size in bytes
     */
    protected static function calculateMysqlDatabaseSize(string $databaseName): int
    {
        try {
            $result = DB::select("
                SELECT ROUND(SUM(data_length + index_length), 0) AS size_bytes
                FROM information_schema.tables 
                WHERE table_schema = ?
            ", [$databaseName]);

            return (int) ($result[0]->size_bytes ?? 0);
        } catch (\Exception $e) {
            Log::error('Failed to calculate MySQL database size', [
                'database' => $databaseName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Calculate PostgreSQL database size
     *
     * @param string $databaseName
     * @return int Size in bytes
     */
    protected static function calculatePostgresDatabaseSize(string $databaseName): int
    {
        try {
            $result = DB::select("
                SELECT pg_database_size(?) as size_bytes
            ", [$databaseName]);

            return (int) ($result[0]->size_bytes ?? 0);
        } catch (\Exception $e) {
            Log::error('Failed to calculate PostgreSQL database size', [
                'database' => $databaseName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Calculate SQL Server database size
     *
     * @param string $databaseName
     * @return int Size in bytes
     */
    protected static function calculateSqlServerDatabaseSize(string $databaseName): int
    {
        try {
            $result = DB::select("
                SELECT SUM(CAST(FILEPROPERTY(name, 'SpaceUsed') AS bigint) * 8192.) AS size_bytes
                FROM sys.database_files
                WHERE type = 0
            ");

            return (int) ($result[0]->size_bytes ?? 0);
        } catch (\Exception $e) {
            Log::error('Failed to calculate SQL Server database size', [
                'database' => $databaseName,
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Clear database usage cache for specific subdomain
     *
     * @param string|null $subdomain
     * @return array
     */
    public static function clearDbUsageCache(?string $subdomain = null): array
    {
        try {
            if (!$subdomain) {
                $subdomain = tenant('subdomain');
            }

            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Subdomain not provided and current subdomain not found',
                    'status' => 404
                ];
            }

            $cacheKey = "tenant_db_usage_{$subdomain}";
            Cache::forget($cacheKey);

            Log::info('Database usage cache cleared successfully', [
                'subdomain' => $subdomain
            ]);

            return [
                'success' => true,
                'message' => 'Database usage cache cleared successfully',
                'data' => [
                    'subdomain' => $subdomain
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to clear database usage cache', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'CLEAR_DB_USAGE_CACHE_FAILED',
                'message' => 'Failed to clear database usage cache: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Get S3 storage usage from cache for current subdomain
     * If not cached, calculate and set it
     *
     * @param string|null $subdomain
     * @param bool $forceRefresh Force refresh cache
     * @return array
     */
    public static function getS3Usage(?string $subdomain = null, bool $forceRefresh = false): array
    {
        try {
            if (!$subdomain) {
                $subdomain = tenant('subdomain');
            }
            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Current subdomain not found',
                    'status' => 404
                ];
            }

            $setResult = self::setS3Usage($subdomain);
            if (!$setResult['success']) {
                return $setResult;
            }

            return [
                'success' => true,
                'data' => $setResult['data'],
                'from_cache' => false
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get S3 usage', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'GET_S3_USAGE_FAILED',
                'message' => 'Failed to get S3 usage: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Set S3 storage usage in cache for specific subdomain
     * Calculates the actual S3 bucket sizes and caches it
     *
     * @param string|null $subdomain
     * @return array
     */
    public static function setS3Usage(?string $subdomain = null): array
    {
        try {
            if (!$subdomain) {
                $subdomain = tenant('subdomain');
            }

            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Subdomain not provided and current subdomain not found',
                    'status' => 404
                ];
            }

            $s3Usage = self::calculateS3Usage($subdomain);

            $usageData = [
                'subdomain' => $subdomain,
                's3_usage' => $s3Usage,
                'total_size_bytes' => $s3Usage['total']['total_size_bytes'],
                'total_size_mb' => $s3Usage['total']['total_size_mb'],
                'total_size_gb' => $s3Usage['total']['total_size_gb'],
            ];

            return [
                'success' => true,
                'data' => $usageData
            ];
        } catch (\Exception $e) {
            Log::error('Failed to set S3 usage', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'SET_S3_USAGE_FAILED',
                'message' => 'Failed to set S3 usage: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Calculate S3 storage usage for specific subdomain
     *
     * @param string $subdomain
     * @return array
     */
    protected static function calculateS3Usage(string $subdomain): array
    {
        try {
            $s3Client = self::getS3Client();
            if (!$s3Client) {
                return [
                    'exment' => ['total_size_bytes' => 0, 'object_count' => 0],
                    'backup' => ['total_size_bytes' => 0, 'object_count' => 0],
                    'template' => ['total_size_bytes' => 0, 'object_count' => 0],
                    'plugin' => ['total_size_bytes' => 0, 'object_count' => 0],
                    'total' => ['total_size_bytes' => 0, 'total_size_mb' => 0, 'total_size_gb' => 0]
                ];
            }

            $buckets = [
                'exment' => config('exment.rootpath.s3.exment'),
                'backup' => config('exment.rootpath.s3.backup'),
                'template' => config('exment.rootpath.s3.template'),
                'plugin' => config('exment.rootpath.s3.plugin'),
            ];

            $results = [];
            $totalSize = 0;

            foreach ($buckets as $type => $bucketName) {
                if ($bucketName) {
                    $result = self::calculateBucketSize($s3Client, $bucketName, $subdomain);
                    $results[$type] = $result;
                    $totalSize += $result['total_size_bytes'];
                } else {
                    $results[$type] = [
                        'bucket' => null,
                        'total_size_bytes' => 0,
                        'total_size_mb' => 0,
                        'total_size_gb' => 0,
                        'object_count' => 0
                    ];
                }
            }

            $results['total'] = [
                'total_size_bytes' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'total_size_gb' => round($totalSize / 1024 / 1024 / 1024, 2),
            ];

            return $results;
        } catch (\Exception $e) {
            Log::error('Failed to calculate S3 usage', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'exment' => ['total_size_bytes' => 0, 'object_count' => 0],
                'backup' => ['total_size_bytes' => 0, 'object_count' => 0],
                'template' => ['total_size_bytes' => 0, 'object_count' => 0],
                'plugin' => ['total_size_bytes' => 0, 'object_count' => 0],
                'total' => ['total_size_bytes' => 0, 'total_size_mb' => 0, 'total_size_gb' => 0]
            ];
        }
    }

    /**
     * Calculate size of a specific S3 bucket
     *
     * @param S3Client $s3Client
     * @param string $bucketName
     * @param string $subdomain
     * @return array
     */
    protected static function calculateBucketSize(S3Client $s3Client, string $bucketName, string $subdomain): array
    {
        try {
            $totalSize = 0;
            $objectCount = 0;
            $continuationToken = null;
            $prefix = "tenant-{$subdomain}/";

            do {
                $params = [
                    'Bucket' => $bucketName,
                    'Prefix' => $prefix,
                    'MaxKeys' => 1000,
                ];

                if ($continuationToken) {
                    $params['ContinuationToken'] = $continuationToken;
                }

                $result = $s3Client->listObjectsV2($params);

                if (isset($result['Contents'])) {
                    foreach ($result['Contents'] as $object) {
                        $totalSize += $object['Size'];
                        $objectCount++;
                    }
                }

                $continuationToken = $result['NextContinuationToken'] ?? null;
            } while ($continuationToken);
            return [
                'bucket' => $bucketName,
                'prefix' => $prefix,
                'total_size_bytes' => $totalSize,
                'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                'total_size_gb' => round($totalSize / 1024 / 1024 / 1024, 2),
                'object_count' => $objectCount
            ];
        } catch (AwsException $e) {
            Log::error('Failed to calculate bucket size', [
                'bucket' => $bucketName,
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'bucket' => $bucketName,
                'prefix' => "tenant-{$subdomain}/",
                'total_size_bytes' => 0,
                'total_size_mb' => 0,
                'total_size_gb' => 0,
                'object_count' => 0,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get S3 client instance
     *
     * @return S3Client|null
     */
    protected static function getS3Client(): ?S3Client
    {
        try {
            $s3Config = config('filesystems.disks.s3');

            if (!$s3Config || !isset($s3Config['key']) || !isset($s3Config['secret'])) {
                Log::warning('S3 configuration not found or incomplete');
                return null;
            }

            return new S3Client([
                'version' => 'latest',
                'region' => 'ap-northeast-1',
                'credentials' => [
                    'key' => $s3Config['key'],
                    'secret' => $s3Config['secret'],
                ],
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create S3 client', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Clear S3 usage cache for specific subdomain
     *
     * @param string|null $subdomain
     * @return array
     */
    public static function clearS3UsageCache(?string $subdomain = null): array
    {
        try {
            if (!$subdomain) {
                $subdomain = tenant('subdomain');
            }

            if (!$subdomain) {
                return [
                    'success' => false,
                    'error' => 'SUBDOMAIN_NOT_FOUND',
                    'message' => 'Subdomain not provided and current subdomain not found',
                    'status' => 404
                ];
            }

            $cacheKey = "tenant_s3_usage_{$subdomain}";
            Cache::forget($cacheKey);

            Log::info('S3 usage cache cleared successfully', [
                'subdomain' => $subdomain
            ]);

            return [
                'success' => true,
                'message' => 'S3 usage cache cleared successfully',
                'data' => [
                    'subdomain' => $subdomain
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to clear S3 usage cache', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'CLEAR_S3_USAGE_CACHE_FAILED',
                'message' => 'Failed to clear S3 usage cache: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }

    /**
     * Get combined usage (database + S3) for current subdomain
     *
     * @param $subdomain
     * @param bool $forceRefreshS3 Force refresh S3 cache
     * @return array
     */
    public static function getCombinedUsage($subdomain = null, bool $forceRefreshS3 = false): array
    {
        try {
            $dbUsage = self::getDbUsage($subdomain);
            $s3Usage = self::getS3Usage($subdomain, $forceRefreshS3);

            if (!$dbUsage['success'] || !$s3Usage['success']) {
                return [
                    'success' => false,
                    'error' => 'COMBINED_USAGE_FAILED',
                    'message' => 'Failed to get combined usage data',
                    'db_error' => $dbUsage['error'] ?? null,
                    's3_error' => $s3Usage['error'] ?? null,
                    'status' => 500
                ];
            }

            $dbData = $dbUsage['data'];
            $s3Data = $s3Usage['data'];

            $totalSize = $dbData['database_size_bytes'] + $s3Data['total_size_bytes'];

            return [
                'success' => true,
                'data' => [
                    'subdomain' => $dbData['subdomain'],
                    'db' => $dbData,
                    's3' => $s3Data,
                    'total' => [
                        'total_size_bytes' => $totalSize,
                        'total_size_mb' => round($totalSize / 1024 / 1024, 2),
                        'total_size_gb' => round($totalSize / 1024 / 1024 / 1024, 2),
                    ],
                    'from_cache' => [
                        'database' => $dbUsage['from_cache'] ?? false,
                        's3' => $s3Usage['from_cache'] ?? false
                    ]
                ]
            ];
        } catch (\Exception $e) {
            Log::error('Failed to get combined usage', [
                'error' => $e->getMessage()
            ]);

            return [
                'success' => false,
                'error' => 'GET_COMBINED_USAGE_FAILED',
                'message' => 'Failed to get combined usage: ' . $e->getMessage(),
                'status' => 500
            ];
        }
    }
}
