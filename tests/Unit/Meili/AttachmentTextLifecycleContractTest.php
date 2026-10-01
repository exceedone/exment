<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Jobs\ExtractAttachmentTextJob;
use Exceedone\Exment\Jobs\ForgetAttachmentTextJob;
use PHPUnit\Framework\TestCase;

/**
 * The File lifecycle crosses Eloquent wildcard listeners, two queue jobs and
 * the record mapper. These focused contracts catch a listener/job being
 * dropped without needing to mutate a developer's attachment storage or DB.
 */
class AttachmentTextLifecycleContractTest extends TestCase
{
    public function testProviderCoversUploadReplaceAndDeleteFileEvents(): void
    {
        $provider = $this->source('src/ExmentServiceProvider.php');

        $this->assertStringContainsString("'eloquent.updating: *'", $provider);
        $this->assertStringContainsString('AttachmentTextSync::rememberUpdating', $provider);
        $this->assertStringContainsString("'eloquent.saved: *'", $provider);
        $this->assertStringContainsString('AttachmentTextSync::handleSaved', $provider);
        $this->assertStringContainsString("'eloquent.deleting: *'", $provider);
        $this->assertStringContainsString('AttachmentTextSync::rememberDeleting', $provider);
        $this->assertStringContainsString("'eloquent.deleted: *'", $provider);
        $this->assertStringContainsString('AttachmentTextSync::handleDeleted', $provider);
    }

    public function testReplacingAMovedFileRefreshesBothTheOldAndNewParentRecords(): void
    {
        $sync = $this->source('src/Services/Meili/AttachmentText/AttachmentTextSync.php');

        $this->assertStringContainsString('$previous !== null && $previous !== $current', $sync);
        $this->assertStringContainsString('self::syncRecord($previous)', $sync);
        $this->assertStringContainsString('ExtractAttachmentTextJob::dispatch', $sync);
    }

    public function testDeletingAFileForgetsItsCacheThenRemapsTheFormerParentRecord(): void
    {
        $job = $this->source('src/Jobs/ForgetAttachmentTextJob.php');

        $this->assertStringContainsString('AttachmentTextCache::forget($this->fileUuid)', $job);
        $this->assertStringContainsString("SyncMeiliDocumentJob::dispatch(\$this->tableName, \$this->valueId, 'upsert')", $job);
    }

    public function testExtractAndForgetJobsHaveDistinctPerFileDeduplicationKeys(): void
    {
        $extract = new ExtractAttachmentTextJob('file-uuid', 'customer', 1);
        $forget = new ForgetAttachmentTextJob('file-uuid', 'customer', 1);

        $this->assertSame('extract-attachment-file-uuid', $extract->uniqueId());
        $this->assertSame('forget-attachment-file-uuid', $forget->uniqueId());
        $this->assertNotSame($extract->uniqueId(), $forget->uniqueId());
    }

    private function source(string $relativePath): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . $relativePath);
    }
}
