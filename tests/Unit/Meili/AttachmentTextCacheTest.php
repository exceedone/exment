<?php

namespace Exceedone\Exment\Tests\Unit\Meili;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\MeiliAttachmentText;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class AttachmentTextCacheTest extends TestCase
{
    public function testReusesOnlyAnUnchangedFileWithTheCurrentExtractorVersion(): void
    {
        $hash = str_repeat('a', 64);
        $cached = new MeiliAttachmentText([
            'file_hash' => $hash,
            'extractor_version' => AttachmentTextCache::EXTRACTOR_VERSION,
        ]);

        $this->assertTrue(AttachmentTextCache::canReuse($cached, $hash));
        $this->assertFalse(AttachmentTextCache::canReuse($cached, str_repeat('b', 64)));
        $this->assertFalse(AttachmentTextCache::canReuse($cached, null));

        $cached->extractor_version = 'outdated';
        $this->assertFalse(AttachmentTextCache::canReuse($cached, $hash));
    }

    public function testPayloadKeepsDuplicateNamesAndFilesWithoutCachedText(): void
    {
        $first = $this->file('file-001', '見積書.pdf');
        $second = $this->file('file-002', '見積書.pdf');
        $third = $this->file('file-003', 'notes.docx');
        $texts = new Collection([
            'file-001' => new MeiliAttachmentText(['text' => '東京営業部 <approved>']),
            'file-002' => new MeiliAttachmentText(['text' => 'Q4 total']),
        ]);

        $payload = AttachmentTextCache::buildPayload(new Collection([$second, $third, $first]), $texts, 1000);

        $this->assertSame([
            ['file_uuid' => 'file-001', 'name' => '見積書.pdf', 'text' => '東京営業部 <approved>'],
            ['file_uuid' => 'file-002', 'name' => '見積書.pdf', 'text' => 'Q4 total'],
            ['file_uuid' => 'file-003', 'name' => 'notes.docx', 'text' => ''],
        ], $payload['attachments']);
    }

    public function testPayloadCapsCombinedTextButRetainsEveryName(): void
    {
        $files = new Collection([
            $this->file('file-001', 'first.docx'),
            $this->file('file-002', 'second.docx'),
            $this->file('file-003', 'third.docx'),
        ]);
        $texts = new Collection([
            'file-001' => new MeiliAttachmentText(['text' => '東京営業部']),
            'file-002' => new MeiliAttachmentText(['text' => 'ABCDEFGHIJ']),
            'file-003' => new MeiliAttachmentText(['text' => 'later text']),
        ]);

        $attachments = AttachmentTextCache::buildPayload($files, $texts, 7)['attachments'];

        $this->assertSame(['first.docx', 'second.docx', 'third.docx'], array_column($attachments, 'name'));
        $this->assertSame('東京営業部', $attachments[0]['text']);
        $this->assertSame('AB', $attachments[1]['text']);
        $this->assertSame('', $attachments[2]['text']);
        $this->assertSame(7, array_sum(array_map(fn ($file) => mb_strlen($file['text'], 'UTF-8'), $attachments)));
    }

    public function testEmptyFileSetProducesAnEmptyAttachmentArray(): void
    {
        $this->assertSame(['attachments' => []], AttachmentTextCache::buildPayload(new Collection(), new Collection(), 10));
    }

    private function file(string $uuid, string $filename): File
    {
        $file = new File();
        $file->uuid = $uuid;
        $file->filename = $filename;
        $file->local_filename = $filename;
        return $file;
    }
}
