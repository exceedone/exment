<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\Installer\SkeletonMigrationReconciler;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

class UpdateCommand extends Command
{
    use CommandTrait;
    use InstallUpdateTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:update
        {--force : On a production site, update the database without asking (a pipe, cron, the update from the screen)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the exment package';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * The exit code of "migrate".
     *
     * @var int
     */
    protected $migrateExitCode = 0;

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
        if (!$this->confirmMigrations()) {
            return 1;
        }

        // a site that had a config cache gets it again at the end (ISS02831)
        $configCached = $this->configCached();

        $this->publishStaticFiles();

        $this->createExmentBootstrapFile();

        $this->initDatabase();
        if ($this->migrateExitCode !== 0) {
            $this->error('The database was not updated (migrate ended with ' . $this->migrateExitCode . ').');
            $this->restoreConfigCache($configCached);
            return $this->migrateExitCode;
        }

        // the site is installed: the web installer stays closed even while the database is down
        try {
            if (hasTable(\Exceedone\Exment\Enums\SystemTableName::SYSTEM)) {
                \Exceedone\Exment\Services\Installer\InstalledState::markInstalled();
            }
        } catch (\Throwable $e) {
        }

        // the features the site has not decided on yet (they are off): told at the end
        try {
            $features = \Exceedone\Exment\Services\PluginDistribution\AdminNotices::newFeatures();
            if (count($features) > 0) {
                $this->warn(strval(exmtrans('plugin_distribution.notice_features', ['names' => implode(', ', $features)])));
            }
        } catch (\Throwable $e) {
            // not told
        }

        // the logs keep no input from 7.3.0 (ISS02839): said once
        try {
            if (!boolval(\Exceedone\Exment\Model\System::log_privacy_notice_shown())) {
                $this->warn(strval(exmtrans('log_management.privacy.update_notice')));
                \Exceedone\Exment\Model\System::log_privacy_notice_shown(true);
            }
        } catch (\Throwable $e) {
            // not told
        }

        // PHP whose support ends, a database older than the recommended version (ISS00171, ISS00216)
        try {
            foreach ((new \Exceedone\Exment\Services\SystemRequire\Security\SupportedVersionSecurity())->findings() as $finding) {
                $this->warn($finding);
            }
        } catch (\Throwable $e) {
            // not told
        }

        $this->restoreConfigCache($configCached);

        return 0;
    }

    /**
     * Whether the site has a config cache (before the update clears it).
     */
    public function configCached(): bool
    {
        return is_file($this->laravel->getCachedConfigPath());
    }

    /**
     * Make the config cache again when there was one: the same routine as the tuning
     * (readable by the owner and the group only). A site without one gets none.
     */
    public function restoreConfigCache(bool $configCached): void
    {
        if (!$configCached) {
            return;
        }
        try {
            AutoTuning::cacheConfig();
            $this->info('config:cache (the site had a config cache)');
        } catch (\Throwable $e) {
            $this->warn('config:cache failed: ' . $e->getMessage());
        }
    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function initDatabase()
    {
        foreach (['cache:clear', 'config:clear', 'route:clear', 'view:clear'] as $command) {
            try {
                $this->call($command);
            } catch (\Exception $ex) {
            }
        }

        $this->prepareSkeletonMigrations();

        // asked once before (confirmMigrations): "migrate" would ask again on a production
        // site, and without a terminal its answer is "no", so the update ended with the
        // database not updated
        $this->migrateExitCode = intval($this->call('migrate', ['--force' => true]));
        if ($this->migrateExitCode !== 0) {
            return;
        }

        // Remove template import if update
        // $importer = new TemplateImporter;
        // $importer->importSystemTemplate(true);
        // the table "System Performance Log" is made only where the log is on
        // (otherwise it is made when someone turns the log on in the system setting)
        TemplateImporter::importSystemLogsTemplateIfEnabled();

        // the cache of Exment (EXMENT_USE_CACHE) may hold the settings of before the update
        \Exceedone\Exment\Model\System::clearCache();

    }

    /**
     * On a production site (APP_ENV=production), the database is updated only when the
     * person running the command says so: --force, or "yes" at the question. Without a
     * terminal (a pipe such as "yes | php artisan exment:update", ssh without a terminal,
     * cron) nothing can be asked: the command stops before it changes anything, and says
     * to add --force.
     */
    protected function confirmMigrations(): bool
    {
        if (boolval($this->option('force')) || !$this->laravel->environment('production')) {
            return true;
        }
        if (!$this->input->isInteractive()) {
            $this->error('This site is in production (APP_ENV=production). To update its database without a terminal, run: php artisan exment:update --force');
            return false;
        }
        return $this->confirm('This site is in production (APP_ENV=production). Update its database now?');
    }

    /**
     * Run the Laravel 11+ skeleton migrations (0001_01_01_*) that find their tables already there,
     * e.g. a v6 backup restored into a fresh v7 install, so that `migrate` does not stop on them.
     *
     * @return void
     */
    protected function prepareSkeletonMigrations()
    {
        /** @var \Illuminate\Database\Migrations\Migrator $migrator */
        $migrator = $this->laravel->make('migrator');
        $migrator->setOutput($this->output);
        $reconciler = new SkeletonMigrationReconciler($migrator);

        foreach ($reconciler->reconcile() as $migration => $created) {
            $this->info("$migration: kept the existing tables" . (empty($created) ? '' : ', created ' . implode(', ', $created)));
        }
    }
}
