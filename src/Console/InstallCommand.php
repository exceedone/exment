<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Console\InstallCommand as AdminInstallCommand;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;

class InstallCommand extends AdminInstallCommand
{
    use CommandTrait;
    use InstallUpdateTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:install {--settings=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the exment package';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        Log::info('InstallCommand: Starting installation process');
        $settings = $this->getSettings();
        
        if ($settings && !empty($settings['db_name'])) {
            $this->setDatabaseConnection($settings);
        }
        $this->publishStaticFiles();

        $this->createExmentBootstrapFile();

        $this->initDatabase();

        $this->initAdminDirectory();

        //$this->call('passport:keys');
        Log::info('InstallCommand: installation completed');
        return 0;
    }
    protected function setDatabaseConnection($settings)
    {
        $connectionName = 'custom_install';
        
        $defaultConnection = config('database.connections.' . config('database.default'));
    
        Config::set("database.connections.$connectionName", array_merge($defaultConnection, [
            'host' => $settings['db_host'] ?? '127.0.0.1',
            'port' => $settings['db_port'] ?? '3306',
            'database' => $settings['db_name'],
            'username' => $settings['db_username'],
            'password' => $settings['db_password'],
        ]));

        // Test connection
        try {
            DB::purge($connectionName);
            DB::connection($connectionName)->getPdo();
            
            Config::set('database.default', $connectionName);
            
            $this->info("Database connection established successfully for: {$settings['db_name']}");
        } catch (\Exception $e) {
            $this->error("Failed to connect to database: " . $e->getMessage());
            throw $e;
        }
    }

    protected function getSettings()
    {
        if ($settingsJson = $this->option('settings')) {
            $decoded = json_decode($settingsJson, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $fixedJson = preg_replace('/(\w+):([^,}]+)/', '"$1":"$2"', $settingsJson);
                $decoded = json_decode($fixedJson, true);
            }
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                $this->error("Invalid JSON format in settings: " . json_last_error_msg());
                return null;
            }
            return $decoded;
        }

        if (function_exists('tenant') && $tenant = tenant()) {
            return $tenant->getEnvironmentSettings();
        }

        return null;
    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function initDatabase()
    {
        $this->call('migrate');

        $this->call('db:seed', ['--class' => \Exceedone\Exment\Database\Seeder\InstallSeeder::class]);
    }
}
