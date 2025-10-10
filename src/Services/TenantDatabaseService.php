<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\System;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exceedone\Exment\Model\Tenant;
use Exception;

/**
 * Tenant Database Service
 * 
 * Handles database creation, user creation, migration, and initialization for tenants.
 * All methods are static for easy access without dependency injection.
 */
class TenantDatabaseService
{
    /**
     * Create database for tenant
     * 
     * Creates a new MySQL database with UTF8MB4 charset for the tenant
     * Updates tenant's environment_settings with database information
     * 
     * @param Tenant $tenant The tenant instance
     * @return void
     * @throws Exception If database creation fails
     */
    public static function createTenantDatabase(Tenant $tenant): void
    {
        $settings = $tenant->getEnvironmentSettings();
        $dbName = 'tenant_' . $tenant->id . '_' . date('Ymd') . '_' . strtolower(\Str::random(8));
        
        $connection = DB::connection();
        Log::info('Creating tenant database', [
            'tenant_id' => $tenant,
            'connection' => $connection,
            'database_name' => $dbName
        ]);
        
        // Connect to MySQL server (without specifying database)
        $connection = DB::connection();
        
        // Create database with proper charset
        $connection->statement("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        
        // Update environment_settings with database information
        $settings['db_name'] = $dbName;
        $settings['db_username'] = 'user_tenant_' . $tenant->id . '_' . strtolower(\Str::random(8));
        $tenant->update(['environment_settings' => $settings]);
        $tenant->setEnvironmentSettings($settings);
        Log::info('Tenant database created successfully', [
            'tenant_id' => $tenant->id,
            'database_name' => $dbName
        ]);
    }
    
    /**
     * Create dedicated database user for tenant
     * 
     * Creates a MySQL user with privileges specific to the tenant's database
     * Generates secure username and password, stores them in environment_settings
     * 
     * @param Tenant $tenant The tenant instance
     * @return void
     * @throws Exception If user creation fails
     */
    public static function createTenantDatabaseUser(Tenant $tenant): void
    {
        $settings = $tenant->getEnvironmentSettings();
        $dbName = $settings['db_name'];
        
        // Generate secure username and password
        $username = $settings['db_username'];
        $password = $settings['db_password'];
        
        Log::info('Creating tenant database user', [
            'tenant_id' => $tenant->id,
            'database_name' => $dbName,
            'username' => $username
        ]);
        
        // Connect to MySQL server as root/admin
        $connection = DB::connection();
        
        // Create user
        $connection->statement("CREATE USER IF NOT EXISTS '{$username}'@'%' IDENTIFIED BY '{$password}'");
        
        // Grant privileges only to the specific tenant database
        $connection->statement("GRANT ALL PRIVILEGES ON `{$dbName}`.* TO '{$username}'@'%'");
        
        // Apply privileges
        $connection->statement("FLUSH PRIVILEGES");
        
        // Update environment_settings with database credentials
        $settings['db_username'] = $username;
        $settings['db_password'] = $password;
        $settings['db_host'] = env('DB_HOST') ?? '127.0.0.1';
        $settings['db_port'] = env('DB_PORT') ?? '3306';
        
        // $tenant->update(['environment_settings' => $settings]);
        
        Log::info('Tenant database user created successfully', [
            'tenant_id' => $tenant->id,
            'database_name' => $dbName,
            'username' => $username
        ]);
    }
    
}