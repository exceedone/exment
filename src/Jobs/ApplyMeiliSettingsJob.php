<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\MeiliDictionary;
use Exceedone\Exment\Services\Meili\FilterConfig;
use Exceedone\Exment\Services\Meili\IndexSettings;
use Exceedone\Exment\Services\Meili\MeiliClientFactory;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Apply the Meilisearch index SETTINGS (synonyms, stop words, ...) WITHOUT
 * reindexing documents. Dispatched when the admin edits the relevance
 * dictionary; settings apply in seconds, so this stays on the light queue.
 *
 * ShouldBeUnique: many rapid dictionary edits collapse into one apply.
 */
class ApplyMeiliSettingsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $uniqueFor = 120;

    public function __construct()
    {
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

        $opts = (array) config('meilisearch.settings', []);
        $opts['range_fields'] = FilterConfig::allRangeFields();
        $opts = MeiliDictionary::mergeIntoOpts($opts);

        $task = $index->updateSettings(IndexSettings::build($opts));
        $client->waitForTask($task['taskUid'], 60000);
    }
}
