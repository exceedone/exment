<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Meili\IndexSettings;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Apply the Meilisearch index SETTINGS (synonyms, stop words, ...) WITHOUT
 * reindexing documents. Dispatched when the admin edits the relevance
 * dictionary; settings apply in seconds, so this stays on the light queue.
 *
 * Unique so many rapid dictionary edits collapse into ONE apply - but only
 * UNTIL PROCESSING: plain ShouldBeUnique holds the lock until handle() returns,
 * and handle() blocks on waitForTask for up to 60s. An edit saved inside that
 * window would have its apply job dropped and never reach the index. For a
 * range filter setting that is silent and harmful: n_<table>::<col> stays
 * out of filterableAttributes, Meilisearch rejects the filter, the search
 * throws, and the page falls back to MySQL showing UNFILTERED results.
 */
class ApplyMeiliSettingsJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use JobTrait;

    public int $backoff = 10;
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->afterCommit();
        
        // try/catch: constructing the job in a bare unit test has no app/config.
        try {
            $this->onQueue(config('meilisearch.sync_queue', 'default'));
        } catch (\Throwable $e) {
            $this->onQueue('default');
        }
    }

    public function uniqueId(): string
    {
        return 'meili-apply-settings';
    }

    public function handle(): void
    {
        $client = MeiliClientFactory::make();
        $index = $client->index(config('meilisearch.index'));

        $task = $index->updateSettings(IndexSettings::build(IndexSettings::fromSystem()));
        $client->waitForTask($task['taskUid'], 60000);
    }
}
