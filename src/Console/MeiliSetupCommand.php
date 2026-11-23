<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\EnvService;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Do the Exment side of the Meilisearch setup in one command:
 * .env (queue), the queue and Meilisearch tables, the system settings (host,
 * key, index, sync, repair), the first index and the health check.
 *
 * Meilisearch itself, the queue worker and the scheduler run outside PHP; the
 * command prints what is left for them (the install scripts do it).
 */
class MeiliSetupCommand extends Command
{
    use CommandTrait;
    use MeiliCommandTrait;

    public const MIN_VERSION = '1.10.0';

    public const REINDEX_QUEUE = 'meili-reindex';

    protected $signature = 'exment:meili-setup
        {--host= : Meilisearch URL (default: the current setting, http://127.0.0.1:7700)}
        {--key= : Master key. A key on the command line shows in the process list: prefer --key-file or the MEILI_MASTER_KEY environment variable}
        {--key-file= : File holding the master key, e.g. /etc/meilisearch.conf (a MEILI_MASTER_KEY= line, or the key alone)}
        {--index= : Index name (default: the current setting, exment_global)}
        {--repair-at= : Time of the daily index repair, HH:MM (default: the current setting, 03:00)}
        {--skip-env : Do not change .env (QUEUE_CONNECTION, MEILISEARCH_REINDEX_QUEUE)}
        {--skip-index : Save the settings, but do not build the index}';

    protected $description = 'Set up Exment for Meilisearch: .env, tables, system settings, index and health check';

    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    public function handle(): int
    {
        if (!$this->assertMeiliSdkInstalled()) {
            return self::FAILURE;
        }

        $host = rtrim((string) ($this->option('host') ?: config('meilisearch.host')), '/');
        if (!preg_match('#^https?://[^\s/]+#i', $host)) {
            $this->error("The host must be a URL such as http://127.0.0.1:7700 (got \"{$host}\").");
            return self::FAILURE;
        }
        $index = (string) ($this->option('index') ?: config('meilisearch.index'));
        if (!preg_match('/^[A-Za-z0-9_-]+$/D', $index)) {
            $this->error("The index name can use only letters, digits, - and _ (got \"{$index}\").");
            return self::FAILURE;
        }
        $repairAt = (string) ($this->option('repair-at') ?: config('meilisearch.repair_at', '03:00'));
        if (!preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/D', $repairAt)) {
            $this->error("--repair-at must be a time such as 03:00 (got \"{$repairAt}\").");
            return self::FAILURE;
        }

        $key = $this->resolveKey();
        if ($key === null) {
            return self::FAILURE;
        }

        // 1. Meilisearch answers, the key works, the version is new enough
        $this->info("[1/5] Meilisearch: {$host}");
        config(['meilisearch.host' => $host, 'meilisearch.key' => $key, 'meilisearch.index' => $index]);
        $client = MeiliClientFactory::make();
        if (!$this->assertMeiliReachable($client)) {
            return self::FAILURE;
        }
        try {
            // /health answers without a key: /version is the first call that needs it
            $version = (string) ($client->version()['pkgVersion'] ?? '');
        } catch (\Throwable $e) {
            $this->error('Meilisearch refused the key: ' . $e->getMessage());
            $this->line('  Check the master key (MEILI_MASTER_KEY of the Meilisearch server).');
            return self::FAILURE;
        }
        if ($version !== '' && version_compare($version, static::MIN_VERSION, '<')) {
            $this->error("Meilisearch {$version} is too old: use " . static::MIN_VERSION . ' or later.');
            return self::FAILURE;
        }
        $this->line('  OK, version ' . ($version ?: '?'));
        if (!$this->assertIndexOwned($client, $index)) {
            return self::FAILURE;
        }

        // 2. .env: a queue that does not block the pages, and a queue of its own for the heavy reindex
        $this->info('[2/5] .env');
        if (!$this->setupEnv()) {
            return self::FAILURE;
        }

        // 3. tables of the queue and of Meilisearch
        $this->info('[3/5] Tables');
        if (!$this->setupTables()) {
            return self::FAILURE;
        }

        // 4. system settings (what the System settings screen saves). Global search
        // is turned on only once the index is built, so the search page never
        // answers from an empty index.
        $this->info('[4/5] System settings');
        System::meili_host($host);
        System::meili_key($key);
        System::meili_index($index);
        System::meili_realtime_sync(1);
        System::meili_repair_enabled(1);
        System::meili_repair_at($repairAt);
        System::clearCache();
        config([
            'meilisearch.realtime_sync' => true,
            'meilisearch.repair_enabled' => true,
            'meilisearch.repair_at' => $repairAt,
        ]);
        $this->line("  host, key, index \"{$index}\", real-time sync on, daily repair at {$repairAt}");

        // 5. index and health
        if ($this->option('skip-index')) {
            $this->info('[5/5] Index: skipped (--skip-index). Run "php artisan exment:meili-index", then turn on global search on the System settings screen.');
        } else {
            $this->info('[5/5] Index');
            if ($this->call('exment:meili-index') !== self::SUCCESS) {
                $this->restartWorkers();
                $this->error('Building the index failed: global search stays off. Fix the error above and run this command again.');
                return self::FAILURE;
            }
            System::meili_global_search(1);
            System::clearCache();
            config(['meilisearch.global_search' => true]);
            $this->line('  Global search is on.');
            if ($this->call('exment:meili-health', ['--failed' => true]) !== self::SUCCESS) {
                return self::FAILURE;
            }
        }

        $this->restartWorkers();
        $this->printNextSteps();

        return self::SUCCESS;
    }

    /**
     * A worker started before keeps the old .env and settings (sync queue, no
     * key): ask it to stop after its job, and its service starts it again with
     * the new ones. Done last: System::clearCache() empties the cache, and the
     * signal with it.
     */
    protected function restartWorkers(): void
    {
        try {
            $this->callSilently('queue:restart');
            $this->line('Asked the running queue workers to restart with the new settings.');
        } catch (\Throwable $e) {
            $this->warn('Could not signal the queue workers (' . $e->getMessage() . '): restart them yourself.');
        }
    }

    /**
     * The master key: --key-file, then MEILI_MASTER_KEY, then --key, then the
     * saved one, then a question. A key that cannot be found ends the command.
     */
    protected function resolveKey(): ?string
    {
        $file = $this->option('key-file');
        if ($file) {
            if (!is_file($file) || !is_readable($file)) {
                $this->error("Cannot read the key file \"{$file}\" (run as a user that can read it, e.g. with sudo).");
                return null;
            }
            $key = static::keyFromText((string) file_get_contents($file));
            if ($key === '') {
                $this->error("No MEILI_MASTER_KEY in \"{$file}\".");
                return null;
            }
            return $key;
        }

        $env = getenv('MEILI_MASTER_KEY');
        if (is_string($env) && trim($env) !== '') {
            return trim($env);
        }
        if ($this->option('key')) {
            return trim((string) $this->option('key'));
        }

        $saved = (string) System::meili_key();
        if ($saved === '') {
            $saved = (string) config('meilisearch.key');
        }
        if ($saved !== '') {
            return $saved;
        }

        if (!$this->input->isInteractive()) {
            $this->error('No master key: give --key-file, the MEILI_MASTER_KEY environment variable or --key.');
            return null;
        }
        $key = trim((string) $this->secret('Master key of Meilisearch (MEILI_MASTER_KEY)'));
        if ($key === '') {
            $this->error('No master key.');
            return null;
        }
        return $key;
    }

    /**
     * The key in a meilisearch.conf (MEILI_MASTER_KEY=...), or a file holding the key alone.
     */
    public static function keyFromText(string $text): string
    {
        if (preg_match('/^\s*(?:export\s+)?MEILI_MASTER_KEY\s*=\s*["\']?([^"\'\r\n#]+)/m', $text, $m)) {
            return trim($m[1]);
        }
        $text = trim($text);
        // the key alone: one line, no "="
        if ($text !== '' && strpbrk($text, "=\r\n") === false) {
            return $text;
        }
        return '';
    }

    protected function setupEnv(): bool
    {
        $connection = (string) config('queue.default');
        $set = [];
        if ($connection === 'sync' || $connection === '') {
            $set['QUEUE_CONNECTION'] = 'database';
        }
        $hasReindexQueue = EnvService::getEnv('MEILISEARCH_REINDEX_QUEUE') !== null;
        if (!$hasReindexQueue) {
            $set['MEILISEARCH_REINDEX_QUEUE'] = static::REINDEX_QUEUE;
        }

        if (empty($set)) {
            $this->line("  OK: QUEUE_CONNECTION={$connection}, MEILISEARCH_REINDEX_QUEUE=" . config('meilisearch.reindex_queue'));
            return true;
        }

        $lines = collect($set)->map(fn ($v, $k) => "{$k}={$v}")->implode(', ');
        if ($this->option('skip-env')) {
            $this->warn("  Not changed (--skip-env). Add to .env yourself: {$lines}");
            return true;
        }

        $path = base_path('.env');
        if (!is_file($path) || !is_writable($path)) {
            $this->error("  Cannot write {$path}. Add to .env yourself, then run this command again: {$lines}");
            return false;
        }
        EnvService::setEnv($set);

        if (isset($set['QUEUE_CONNECTION'])) {
            config(['queue.default' => $set['QUEUE_CONNECTION']]);
        }
        if (isset($set['MEILISEARCH_REINDEX_QUEUE'])) {
            config(['meilisearch.reindex_queue' => $set['MEILISEARCH_REINDEX_QUEUE']]);
        }
        $this->line("  Written: {$lines}");

        return true;
    }

    protected function setupTables(): bool
    {
        $missing = [];
        if (config('queue.default') === 'database') {
            $jobs = (string) config('queue.connections.database.table', 'jobs');
            if (!Schema::hasTable($jobs)) {
                $missing[] = $jobs;
                if (!$this->migrationExists('create_jobs_table')) {
                    $this->call('queue:table');
                }
            }
            $failed = (string) config('queue.failed.table', 'failed_jobs');
            if (in_array(config('queue.failed.driver'), ['database', 'database-uuids'], true) && !Schema::hasTable($failed)) {
                $missing[] = $failed;
                if (!$this->migrationExists('create_failed_jobs_table') && !$this->migrationExists('create_jobs_table', 'failed_jobs')) {
                    $this->call('queue:failed-table');
                }
            }
        }
        foreach (['meili_filter_settings', 'meili_saved_searches', 'meili_dictionaries'] as $table) {
            if (!Schema::hasTable($table)) {
                $missing[] = $table;
            }
        }

        if (empty($missing)) {
            $this->line('  OK');
            return true;
        }

        $this->line('  Creating: ' . implode(', ', $missing));
        if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS) {
            $this->error('  "php artisan migrate" failed.');
            return false;
        }
        return true;
    }

    /**
     * Whether the Laravel project already has the migration: a "create_jobs_table"
     * of Laravel 11 or later makes failed_jobs too.
     */
    protected function migrationExists(string $name, ?string $contains = null): bool
    {
        foreach (glob(database_path('migrations/*_' . $name . '.php')) ?: [] as $file) {
            if ($contains === null || strpos((string) file_get_contents($file), $contains) !== false) {
                return true;
            }
        }
        return false;
    }

    protected function printNextSteps(): void
    {
        $php = PHP_BINARY;
        $base = base_path();
        $queues = implode(',', array_unique([
            (string) config('meilisearch.sync_queue', 'default'),
            (string) config('meilisearch.reindex_queue', 'default'),
        ]));

        $this->newLine();
        $this->info('Exment is set up. Two things must keep running (the install scripts register them for you):');
        $this->line('  - Queue worker: ' . $php . ' artisan queue:work --queue=' . $queues . ' --max-time=3600 --tries=3');
        $this->line('    (in ' . $base . ', as a service that restarts by itself)');
        $this->line('  - Scheduler, every minute: ' . $php . ' ' . $base . DIRECTORY_SEPARATOR . 'artisan schedule:run');
    }
}
