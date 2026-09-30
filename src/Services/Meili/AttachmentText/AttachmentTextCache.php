<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\MeiliAttachmentText;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Persist and retrieve attachment text for the record-level Meilisearch
 * document. Cache rows are keyed by File UUID and invalidated by SHA-256 of
 * the stored bytes, so a metadata-only File save never reparses the document.
 */
final class AttachmentTextCache
{
    /** Bump when an extractor change requires reparsing unchanged files. */
    public const EXTRACTOR_VERSION = '1';

    public function __construct(private readonly AttachmentTextExtractor $extractor)
    {
    }

    public static function fromApplicationConfig(): self
    {
        return new self(AttachmentTextExtractor::fromApplicationConfig());
    }

    /**
     * Extract and cache one File. A cached outcome (including unsupported and
     * failed outcomes) is reused only when its source bytes are unchanged.
     *
     * @return MeiliAttachmentText|null null when the cache table/storage cannot be used
     */
    public function refresh(File $file): ?MeiliAttachmentText
    {
        try {
            $sourceHash = $this->sourceHash($file);
            $cached = MeiliAttachmentText::query()->where('file_uuid', (string) $file->uuid)->first();
            if (self::canReuse($cached, $sourceHash)) {
                return $cached;
            }

            $result = $this->extractor->extract($file);

            return MeiliAttachmentText::updateOrCreate(
                ['file_uuid' => (string) $file->uuid],
                [
                    // extractPath calculates the hash while parsing. Keep the
                    // preflight hash when extraction failed before staging.
                    'file_hash' => $result->sha256 ?? $sourceHash,
                    'extractor_version' => self::EXTRACTOR_VERSION,
                    'status' => $result->status,
                    'parser' => $result->parser,
                    'extension' => $result->extension,
                    'mime' => $result->mime,
                    'size_bytes' => $result->sizeBytes,
                    'text' => $result->text,
                    'text_length' => $result->charCount,
                    'truncated' => $result->truncated,
                    'error_code' => $result->errorCode,
                    'metadata' => $result->metadata,
                    'extracted_at' => now(),
                ]
            );
        } catch (\Throwable $e) {
            // Extraction must never make an upload/update fail. The job keeps
            // the record searchable by its ordinary fields and logs the cause.
            Log::warning('[Meili] attachment cache refresh failed: ' . $e->getMessage(), [
                'file_uuid' => $file->uuid,
            ]);
            // A previously READY row must not leak stale text after a failed
            // replacement. If the delete fails, retry the job instead of
            // publishing the old cached content.
            self::forget((string) $file->uuid);
            return null;
        }
    }

    public static function forget(string $fileUuid): void
    {
        try {
            MeiliAttachmentText::query()->where('file_uuid', $fileUuid)->delete();
        } catch (\Throwable $e) {
            Log::warning('[Meili] attachment cache delete failed: ' . $e->getMessage(), [
                'file_uuid' => $fileUuid,
            ]);
            // Publishing the parent before the stale cache row is gone would
            // bring deleted text back into the index.
            throw $e;
        }
    }

    /**
     * The reusable-cache decision is deliberately kept independent of storage
     * and the database so it has a small, deterministic unit-test boundary.
     */
    public static function canReuse(?MeiliAttachmentText $cached, ?string $sourceHash): bool
    {
        return $cached !== null
            && $sourceHash !== null
            && $cached->file_hash !== null
            && hash_equals((string) $cached->file_hash, $sourceHash)
            && $cached->extractor_version === self::EXTRACTOR_VERSION;
    }

    /** Whether a record still owns any business attachment (including a file
     * whose text is unsupported or failed, because its filename is searchable). */
    public static function hasAttachmentForRecord(string $tableName, $recordId): bool
    {
        try {
            return File::query()
                ->where('parent_type', $tableName)
                ->where('parent_id', $recordId)
                ->whereIn('file_type', [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT])
                ->exists();
        } catch (\Throwable $e) {
            // Do not delete an existing document merely because the files table
            // is temporarily unavailable during deployment.
            Log::warning('[Meili] attachment existence lookup failed: ' . $e->getMessage(), [
                'table' => $tableName,
                'record_id' => $recordId,
            ]);
            return true;
        }
    }

    /**
     * Text and names to merge into one record document. Only File types 1 and
     * 2 are business attachments; logos, avatars and public-form assets stay
     * outside the search corpus.
     *
     * @return array{attachments:array<int,array{file_uuid:string,name:string,text:string}>}
     */
    public static function forRecord(string $tableName, $recordId): array
    {
        try {
            $files = File::query()
                ->where('parent_type', $tableName)
                ->where('parent_id', $recordId)
                ->whereIn('file_type', [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT])
                ->orderBy('uuid')
                ->get(['uuid', 'filename', 'local_filename']);
            return self::buildPayload($files, self::cachedTexts($files));
        } catch (\Throwable $e) {
            Log::warning('[Meili] attachment cache lookup failed: ' . $e->getMessage(), [
                'table' => $tableName,
                'record_id' => $recordId,
            ]);
            // An unavailable files table is not evidence that the record lost
            // its attachments. Let the sync retry instead of publishing [].
            throw $e;
        }
    }

    /**
     * Batch version used by index/reindex jobs. It avoids one `files` query
     * and one cache query per record during a large backfill.
     *
     * @param array<int,int|string> $recordIds
     * @return array<string,array{attachments:array<int,array{file_uuid:string,name:string,text:string}>}>
     */
    public static function forRecords(string $tableName, array $recordIds): array
    {
        if (empty($recordIds)) {
            return [];
        }
        try {
            $files = File::query()
                ->where('parent_type', $tableName)
                ->whereIn('parent_id', $recordIds)
                ->whereIn('file_type', [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT])
                ->orderBy('uuid')
                ->get(['uuid', 'parent_id', 'filename', 'local_filename']);
            if ($files->isEmpty()) {
                return [];
            }

            $texts = self::cachedTexts($files);
            $out = [];
            foreach ($files->groupBy(fn ($file) => (string) $file->parent_id) as $recordId => $recordFiles) {
                $out[(string) $recordId] = self::buildPayload($recordFiles, $texts);
            }
            return $out;
        } catch (\Throwable $e) {
            Log::warning('[Meili] attachment batch cache lookup failed: ' . $e->getMessage(), [
                'table' => $tableName,
                'record_count' => count($recordIds),
            ]);
            // Never overwrite a previously indexed attachment with an empty
            // list merely because this lookup failed during a reindex.
            throw $e;
        }
    }

    /** @return \Illuminate\Support\Collection<string,MeiliAttachmentText> */
    private static function cachedTexts($files)
    {
        if ($files->isEmpty()) {
            return collect();
        }
        try {
            return MeiliAttachmentText::query()
                ->whereIn('file_uuid', $files->pluck('uuid')->all())
                ->where('status', ExtractionResult::READY)
                ->get()
                ->keyBy('file_uuid');
        } catch (\Throwable $e) {
            // File metadata is enough to index names even if the text cache is
            // temporarily unavailable.
            Log::warning('[Meili] attachment text lookup failed: ' . $e->getMessage());
            return collect();
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int,File> $files
     * @param \Illuminate\Support\Collection<string,MeiliAttachmentText> $texts
     * @return array{attachments:array<int,array{file_uuid:string,name:string,text:string}>}
     */
    public static function buildPayload($files, $texts, ?int $maxCharacters = null): array
    {
        $maxCharacters = max(1, $maxCharacters ?? (int) config('meilisearch.attachment_extraction.max_record_characters', 1000000));
        $remaining = $maxCharacters;
        $attachments = [];
        foreach ($files->sortBy(fn ($file) => (string) $file->uuid)->values() as $file) {
            $name = trim((string) ($file->filename ?: $file->local_filename));
            $text = (string) ($texts->get($file->uuid)->text ?? '');
            if ($text !== '' && $remaining > 0) {
                $length = mb_strlen($text, 'UTF-8');
                if ($length > $remaining) {
                    $text = mb_substr($text, 0, $remaining, 'UTF-8');
                    $length = $remaining;
                }
                $remaining -= $length;
            } else {
                $text = '';
            }
            $attachments[] = [
                'file_uuid' => (string) $file->uuid,
                'name' => $name,
                'text' => $text,
            ];
        }
        return ['attachments' => $attachments];
    }

    private function sourceHash(File $file): ?string
    {
        try {
            $disk = Storage::disk(config('admin.upload.disk'));
            $stream = $disk->readStream((string) $file->path);
            if (!is_resource($stream)) {
                return null;
            }
            $hash = hash_init('sha256');
            $maxBytes = max(1, (int) config('meilisearch.attachment_extraction.max_bytes', 25 * 1024 * 1024));
            $read = 0;
            try {
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
                        return null;
                    }
                    $read += strlen($chunk);
                    // Do not hash an unbounded remote object just to discover
                    // it will be rejected by the extractor's size limit.
                    if ($read > $maxBytes) {
                        return null;
                    }
                    hash_update($hash, $chunk);
                }
                return hash_final($hash);
            } finally {
                fclose($stream);
            }
        } catch (\Throwable $e) {
            return null;
        }
    }
}
