<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class TenantUsageService
{
    /**
     * Get current subdomain from request or config
     *
     * @return string|null
     */
    public static function getCurrentSubdomain(): ?string
    {
        try {
            $host = request()->getHost();
            $baseDomain = Config::get('exment.tenant.base_domain');

            if ($baseDomain && substr($host, -strlen('.' . $baseDomain)) === '.' . $baseDomain) {
                return str_replace('.' . $baseDomain, '', $host);
            }

            $subdomain = request()->get('subdomain');
            if ($subdomain) {
                return strtolower(trim($subdomain));
            }

            return null;
        } catch (\Exception $e) {
            Log::error('Failed to get current subdomain', [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * Get database usage from cache for current subdomain
     * If not cached, calculate and set it
     *
     * @return array
     */
    public static function getDbUsage(): array
    {
        try {
            $subdomain = self::getCurrentSubdomain();
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
                $subdomain = self::getCurrentSubdomain();
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
                'calculated_at' => now()->toISOString(),
                'cache_expires_at' => now()->addHours(1)->toISOString()
            ];

            $cacheKey = "tenant_db_usage_{$subdomain}";
            Cache::put($cacheKey, $usageData, 3600);

            Log::info('Database usage cached successfully', [
                'subdomain' => $subdomain,
                'size_bytes' => $dbSize,
                'size_mb' => $usageData['database_size_mb']
            ]);

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
            $databaseName = self::getDatabaseNameForSubdomain($subdomain);
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
     * Get database name for subdomain
     *
     * @param string $subdomain
     * @return string|null
     */
    protected static function getDatabaseNameForSubdomain(string $subdomain): ?string
    {
        try {
            $tenant = Tenant::where('subdomain', $subdomain)->first();
            if (!$tenant) {
                return null;
            }

            if (isset($tenant->plan_info['database_name'])) {
                return $tenant->plan_info['database_name'];
            }

            $defaultDbName = Config::get('database.connections.' . Config::get('database.default') . '.database');
            return $defaultDbName;
        } catch (\Exception $e) {
            Log::error('Failed to get database name for subdomain', [
                'subdomain' => $subdomain,
                'error' => $e->getMessage()
            ]);
            return null;
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
                $subdomain = self::getCurrentSubdomain();
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
}


