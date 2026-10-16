<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Meili\IndexSettings;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Console\Command;

/**
 * Apply settings to the Meilisearch index WITHOUT reindexing the data.
 *
 * Use when you change config('meilisearch.settings') (e.g. adding synonyms) and want to apply it immediately.
 */
class MeiliSettingsCommand extends Command
{
    use CommandTrait;
    use MeiliCommandTrait;

    protected $signature = 'exment:meili-settings {--show : Only display the index current settings}';

    protected $description = 'Apply/view the relevance settings of the Meilisearch index';

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

        if (!$this->assertMeiliReachable($client)) {
            return self::FAILURE;
        }

        $index = $client->index(config('meilisearch.index'));

        if ($this->option('show')) {
            $current = $index->getSettings();
            $this->line((string) json_encode([
                'searchableAttributes' => $current['searchableAttributes'] ?? null,
                'filterableAttributes' => $current['filterableAttributes'] ?? null,
                'stopWords' => $current['stopWords'] ?? null,
                'synonyms' => $current['synonyms'] ?? null,
                'localizedAttributes' => $current['localizedAttributes'] ?? null,
                'typoTolerance' => $current['typoTolerance'] ?? null,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            return self::SUCCESS;
        }

        $opts = IndexSettings::fromSystem();
        $settings = IndexSettings::build($opts);

        $this->info('Applying settings to index "' . config('meilisearch.index') . '"...');
        $task = $index->updateSettings($settings);
        $client->waitForTask($task['taskUid'], 60000);

        $this->info('Done. searchableAttributes = ' . implode(', ', $settings['searchableAttributes']));
        $synCount = count((array) $settings['synonyms']);
        $swCount = count($settings['stopWords']);
        $this->line("  synonyms: {$synCount} entries, stopWords: {$swCount} words, typo: " . ($settings['typoTolerance']['enabled'] ? 'on' : 'off'));

        return self::SUCCESS;
    }
}
