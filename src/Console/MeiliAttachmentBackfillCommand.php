<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Jobs\ExtractAttachmentTextJob;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliRuntime;
use Illuminate\Console\Command;

/** Queue or run extraction for existing business attachments. */
class MeiliAttachmentBackfillCommand extends Command
{
    use CommandTrait;

    protected $signature = 'exment:meili-attachments-backfill
        {--table= : One search-enabled custom table (default: all)}
        {--sync : Extract in this command instead of dispatching attachment jobs}
        {--reindex : Queue one reindex per selected table (requires --sync)}';

    protected $description = 'Backfill cached text for PDF, DOCX, PPTX and XLSX business attachments';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $syncDriver = MeiliRuntime::queueUsesSyncDriver();
        if ($this->option('reindex') && !$this->option('sync')) {
            $this->error('--reindex requires --sync so indexing cannot race queued extraction jobs.');
            return self::FAILURE;
        }
        if ($this->option('reindex') && $syncDriver) {
            $this->error('--reindex cannot queue table jobs with the sync queue driver. Run exment:meili-attachments-backfill --sync, then php artisan exment:meili-index.');
            return self::FAILURE;
        }
        if (!$this->option('sync') && $syncDriver) {
            $this->error('Queued attachment extraction is unavailable with the sync queue driver. Run exment:meili-attachments-backfill --sync from CLI.');
            return self::FAILURE;
        }
        if (!$this->option('sync') && !MeiliRuntime::realtimeSyncEnabled()) {
            $this->error('Queued attachment extraction requires MEILISEARCH_REALTIME_SYNC. Enable it, or use --sync to build only the cache.');
            return self::FAILURE;
        }

        $tables = CustomTable::allRecords()
            ->filter(fn ($table) => ExmentIndexer::isAttachmentCapable($table));
        $requested = trim((string) $this->option('table'));
        if ($requested !== '') {
            $tables = $tables->filter(fn ($table) => $table->table_name === $requested);
            if ($tables->isEmpty()) {
                $this->error("Table '{$requested}' is not search-enabled.");
                return self::FAILURE;
            }
        }
        if ($tables->isEmpty()) {
            $this->warn('No search-enabled custom table was found.');
            return self::SUCCESS;
        }

        $tableNames = $tables->pluck('table_name')->all();
        $counts = ['files' => 0, 'queued' => 0, 'ready' => 0, 'skipped' => 0, 'failed' => 0];
        $cache = $this->option('sync') ? AttachmentTextCache::fromApplicationConfig() : null;

        $this->info(($this->option('sync') ? 'Extracting' : 'Queueing extraction for') . ' attachments in ' . count($tableNames) . ' table(s)...');
        File::query()
            ->whereIn('file_type', [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT])
            ->whereIn('parent_type', $tableNames)
            ->orderBy('uuid')
            ->chunk(100, function ($files) use (&$counts, $cache, $syncDriver) {
                foreach ($files as $file) {
                    $counts['files']++;
                    if ($cache === null) {
                        ExtractAttachmentTextJob::dispatch((string) $file->uuid, (string) $file->parent_type, $file->parent_id);
                        $counts['queued']++;
                        continue;
                    }

                    $row = $cache->refresh($file);
                    if ($row === null) {
                        $counts['failed']++;
                        continue;
                    }
                    if ($row->status === \Exceedone\Exment\Services\Meili\AttachmentText\ExtractionResult::READY) {
                        $counts['ready']++;
                    } else {
                        $counts['skipped']++;
                    }
                    // A sync queue would send one HTTP request per file inline.
                    // Publish the cached text with a full index after this run.
                    if (!$syncDriver && !$this->option('reindex') && MeiliRuntime::realtimeSyncEnabled()) {
                        SyncMeiliDocumentJob::dispatch((string) $file->parent_type, $file->parent_id, 'upsert');
                    }
                }
            });

        if ($this->option('reindex')) {
            if (!MeiliRuntime::realtimeSyncEnabled()) {
                $this->warn('Extraction is complete, but --reindex was not queued because automatic Meilisearch sync is disabled. Enable it, then run `php artisan exment:meili-index`.');
            } else {
                foreach ($tableNames as $tableName) {
                    ReindexMeiliTableJob::dispatchUnlessBlocking($tableName);
                }
                $this->line('Queued reindex for: ' . implode(', ', $tableNames));
            }
        }

        $this->info(sprintf(
            'Done. files=%d, queued=%d, ready=%d, non-searchable=%d, failed=%d',
            $counts['files'],
            $counts['queued'],
            $counts['ready'],
            $counts['skipped'],
            $counts['failed'],
        ));
        if ($this->option('sync') && $syncDriver) {
            $this->line('Cache backfill finished. Run php artisan exment:meili-index to publish attachment text.');
        } elseif (!$this->option('sync')) {
            $this->line('Run a worker for the attachment queue: php artisan queue:work --queue=' . config('meilisearch.attachment_queue', 'meili-attachments') . ',default');
        }

        return self::SUCCESS;
    }
}
