<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\FileServer\FileSearch;
use Illuminate\Console\Command;

/**
 * File server, phase 4 (ISS00378): take the text of the files that do not
 * have it yet, and put all the folders and files into the index of
 * Meilisearch again (when exment.file_server_meili is on).
 */
class FileServerIndexCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'exment:file-server-index {--no-text : do not take the text of the files}';

    /**
     * @var string
     */
    protected $description = 'Take the text of the files of the file server for the search, and rebuild its index of Meilisearch.';

    public function handle(): int
    {
        $result = FileSearch::rebuild(!boolval($this->option('no-text')), function (int $count) {
            $this->line("... {$count}");
        });
        $this->info("nodes: {$result['indexed']}, texts: {$result['texts']}" . (FileSearch::meiliEnabled() ? ', meilisearch: ' . FileSearch::indexName() : ''));
        return 0;
    }
}
