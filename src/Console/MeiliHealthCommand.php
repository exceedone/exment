<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Console\Command;

/**
 * Check that Meilisearch is alive + index stats (for monitoring / cron health-check).
 * Exit code 0 = healthy, 1 = error -> easy to plug into a monitoring system.
 */
class MeiliHealthCommand extends Command
{
    use MeiliCommandTrait;

    protected $signature = 'meili:health {--failed : Show the number of failed sync jobs}';

    protected $description = 'Check Meilisearch status (health + document count in the index)';

    public function handle(): int
    {
        if (!$this->assertMeiliSdkInstalled()) {
            return self::FAILURE;
        }

        $service = new MeiliSearchService(
            MeiliClientFactory::make(),
            config('meilisearch.index')
        );

        if (!$service->isAvailable()) {
            $this->error('[Meili] Could NOT connect to ' . config('meilisearch.host'));
            return self::FAILURE;
        }

        $this->info('[Meili] OK - ' . config('meilisearch.host'));

        // Stats on the number of documents in the merged index.
        try {
            $client = MeiliClientFactory::make();
            $stats = $client->index(config('meilisearch.index'))->stats();
            $this->line('  Index : ' . config('meilisearch.index'));
            $this->line('  Docs  : ' . ($stats['numberOfDocuments'] ?? '?'));
            $this->line('  Indexing: ' . (($stats['isIndexing'] ?? false) ? 'yes' : 'no'));
        } catch (\Throwable $e) {
            $this->warn('  Could not read index stats: ' . $e->getMessage());
        }

        if ($this->option('failed')) {
            // Only meaningful with the database queue driver; count only the
            // Meili jobs so unrelated failures are not reported as sync issues.
            try {
                $failed = \DB::table('failed_jobs')->where('payload', 'LIKE', '%Meili%')->count();
                $pending = \DB::table('jobs')->where('payload', 'LIKE', '%Meili%')->count();
                $this->line("  Queue : pending={$pending}, failed={$failed} (Meili jobs, database driver)");
                if ($failed > 0) {
                    $this->warn("  {$failed} Meili job(s) failed -> consider `php artisan queue:retry all`");
                }
            } catch (\Throwable $e) {
                $this->warn('  Could not read queue tables (non-database queue driver?): ' . $e->getMessage());
            }
        }

        return self::SUCCESS;
    }
}
