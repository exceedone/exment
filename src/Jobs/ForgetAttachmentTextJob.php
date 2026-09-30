<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache;
use Exceedone\Exment\Services\Meili\MeiliRuntime;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/** Remove a deleted file's cache row, then remap its former record. */
class ForgetAttachmentTextJob implements ShouldQueue, ShouldBeUniqueUntilProcessing
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
        return 'forget-attachment-' . $this->fileUuid;
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        if (!MeiliRuntime::realtimeSyncEnabled()) {
            return;
        }

        AttachmentTextCache::forget($this->fileUuid);
        try {
            SyncMeiliDocumentJob::dispatch($this->tableName, $this->valueId, 'upsert');
        } catch (\Throwable $e) {
            Log::warning('[Meili] deleted attachment owner sync dispatch failed: ' . $e->getMessage(), [
                'file_uuid' => $this->fileUuid,
            ]);
        }
    }
}
