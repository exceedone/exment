<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Meili\DocumentMapper;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Console\Command;

class MeiliIndexCommand extends Command
{
    use MeiliCommandTrait;

    protected $signature = 'meili:index {--fresh : Delete and recreate the index before indexing}';

    protected $description = 'Index Exment data (search-enabled custom tables) into Meilisearch';

    public function handle(): int
    {
        if (!$this->assertMeiliSdkInstalled()) {
            return self::FAILURE;
        }

        $client = MeiliClientFactory::make();

        // Check the Meilisearch connection.
        try {
            $health = $client->health();
            if (($health['status'] ?? null) !== 'available') {
                $this->error('Meilisearch is not available: ' . json_encode($health));
                return self::FAILURE;
            }
        } catch (\Throwable $e) {
            $this->error('Could not connect to Meilisearch (' . config('meilisearch.host') . '): ' . $e->getMessage());
            return self::FAILURE;
        }

        $indexName = config('meilisearch.index');
        $indexer = new ExmentIndexer(
            $client,
            new DocumentMapper(),
            $indexName,
            (int) config('meilisearch.batch_size')
        );

        $tables = $indexer->searchableTables();
        if ($tables->isEmpty()) {
            $this->warn('No search-enabled custom table with a freeword column to index.');
            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Indexing %d table(s) into "%s"%s...',
            $tables->count(),
            $indexName,
            $this->option('fresh') ? ' (fresh)' : ''
        ));

        $result = $indexer->indexAll((bool) $this->option('fresh'));

        foreach ($result['perTable'] as $name => $count) {
            $this->line(sprintf('  - %-30s %d records', $name, $count));
        }
        $this->info('Total: ' . $result['total'] . ' documents indexed.');

        return self::SUCCESS;
    }
}
