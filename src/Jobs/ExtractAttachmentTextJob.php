<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextSync;
use Exceedone\Exment\Services\Meili\MeiliRuntime;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/** Extract/cache one attachment, then refresh its parent record document. */
class ExtractAttachmentTextJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
{
    use JobTrait;

    public int $backoff = 10;
    public int $uniqueFor = 300;

    /** @param mixed $valueId */
    public function __construct(public string $fileUuid, public string $tableName, public $valueId)
    {
        $this->afterCommit();
        try {
            $this->onQueue(config('meilisearch.attachment_queue', 'meili-attachments'));
        } catch (\Throwable $e) {
            $this->onQueue('meili-attachments');
        }
    }

    public function uniqueId(): string
    {
        return 'extract-attachment-' . $this->fileUuid;
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        if (!MeiliRuntime::realtimeSyncEnabled()) {
            return;
        }

        $file = File::query()->where('uuid', $this->fileUuid)->first();
        if (!$file) {
            return; // deleted before this queued extraction began.
        }
        $target = AttachmentTextSync::targetForFile($file);
        if ($target === null
            || $target['table_name'] !== $this->tableName
            || (string) $target['value_id'] !== (string) $this->valueId) {
            return; // file was moved; its newer saved event owns the refresh.
        }

        if (MeiliRuntime::queueUsesSyncDriver()) {
            // File bytes may not have been stored yet when a sync queue runs
            // inside the upload request. Do not keep text from an older file.
            AttachmentTextCache::forget($this->fileUuid);
            Log::warning('[Meili] attachment text extraction skipped because the queue driver is sync; filename remains searchable', [
                'file_uuid' => $this->fileUuid,
            ]);
        } else {
            (new AttachmentTextCache(\Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextExtractor::fromApplicationConfig()))->refresh($file);
        }

        try {
            SyncMeiliDocumentJob::dispatch($this->tableName, $this->valueId, 'upsert');
        } catch (\Throwable $e) {
            Log::warning('[Meili] attachment owner sync dispatch failed: ' . $e->getMessage(), [
                'file_uuid' => $this->fileUuid,
            ]);
        }
    }
}
