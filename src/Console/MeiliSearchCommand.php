<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Exceedone\Exment\Services\Meili\MeiliSearchService;
use Illuminate\Console\Command;

class MeiliSearchCommand extends Command
{
    use CommandTrait;
    use MeiliCommandTrait;

    protected $signature = 'exment:meili-search {query : Search keyword}
        {--limit=10 : Maximum number of results}
        {--table= : Filter by table_name}
        {--highlight : Show the highlighted matching snippet}
        {--facets : Count results per table}';

    protected $description = 'Global search via Meilisearch (integration check) + timing';

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

        $client = MeiliClientFactory::make();

        try {
            $client->health();
        } catch (\Throwable $e) {
            $this->error('Could not connect to Meilisearch (' . config('meilisearch.host') . '): ' . $e->getMessage());
            return self::FAILURE;
        }

        $service = new MeiliSearchService($client, config('meilisearch.index'));

        $q = (string) $this->argument('query');
        $limit = (int) $this->option('limit');
        $table = $this->option('table') ?: null;

        $start = microtime(true);
        if ($this->option('highlight')) {
            $hits = $service->searchHighlighted($q, $limit);
        } else {
            $hits = $service->search($q, $limit, $table);
        }
        $elapsedMs = (microtime(true) - $start) * 1000;

        // Facet: count per table (#2).
        if ($this->option('facets')) {
            $facets = $service->searchFacets($q);
            $this->info(sprintf('Result count per table for "%s":', $q));
            $this->table(
                ['table', 'result count'],
                array_map(fn ($f) => [$f['table'], $f['count']], $facets)
            );
            $this->newLine();
        }

        if (empty($hits)) {
            $this->warn(sprintf('No results for "%s" (%.2f ms).', $q, $elapsedMs));
            return self::SUCCESS;
        }

        $this->info(sprintf('%d result(s) for "%s" (%.2f ms):', count($hits), $q, $elapsedMs));

        if ($this->option('highlight')) {
            // Show the snippet, turning the highlight markers into [..] for readability on the terminal.
            $this->table(
                ['table_name', 'value_id', 'matching snippet (highlight)'],
                array_map(function ($h) {
                    $snippet = str_replace(
                        [MeiliSearchService::HIGHLIGHT_PRE, MeiliSearchService::HIGHLIGHT_POST],
                        ['[', ']'],
                        // Always set on the --highlight path; defaulted so the
                        // shared $hits shape (search() has no snippet) stays safe.
                        $h['snippet'] ?? ''
                    );
                    return [$h['table_name'], $h['value_id'], $snippet];
                }, $hits)
            );
        } else {
            $this->table(
                ['table_name', 'value_id', 'label'],
                array_map(fn ($h) => [$h['table_name'], $h['value_id'], $h['label']], $hits)
            );
        }

        return self::SUCCESS;
    }
}
