<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\MeiliAttachmentText;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
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
     * @throws \Throwable when a stale row could not be cleared - the caller must
     *   decide between retrying and skipping rather than publish old text
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
     * Delete cache rows whose File row no longer exists. Nothing else prunes
     * this table: a file deleted while the attachment queue had no worker, or
     * an extraction that finished after its file was removed, leaves a row
     * behind forever.
     *
     * @return int rows deleted
     */
    public static function pruneOrphanRows(int $chunkSize = 1000): int
    {
        $deleted = 0;
        $chunkSize = max(1, $chunkSize);

        while (true) {
            $uuids = MeiliAttachmentText::query()
                ->whereNotExists(function ($query) {
                    $query->selectRaw('1')
                        ->from('files')
                        ->whereColumn('files.uuid', 'meili_attachment_texts.file_uuid');
                })
                ->limit($chunkSize)
                ->pluck('file_uuid')
                ->all();
            if (empty($uuids)) {
                break;
            }
            $deleted += MeiliAttachmentText::query()->whereIn('file_uuid', $uuids)->delete();
        }

        return $deleted;
    }

    /**
     * Attachment file uuids a table currently owns, per record - the database
     * side of the drift comparison.
     *
     * @return array<string,array<int,string>>
     */
    public static function currentAttachmentUuids(string $tableName): array
    {
        $table = CustomTable::getEloquent($tableName);
        if (!$table) {
            return [];
        }

        $rows = File::query()
            ->where('parent_type', $tableName)
            ->tap(fn ($query) => self::scopeBusinessFiles($query, $tableName))
            // Restricted to the records that SHOULD hold a document. A
            // soft-deleted record keeps its file rows, and reporting it as
            // drifted is a dead end: reindexIds() cannot write a document for a
            // record recordsQuery() excludes, so it would drift forever.
            ->whereIn('parent_id', ExmentIndexer::recordsQuery($table)->select('id'))
            ->get(['uuid', 'parent_id']);

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->parent_id][] = (string) $row->uuid;
        }
        foreach ($out as $id => $uuids) {
            sort($uuids);
            $out[$id] = $uuids;
        }

        return $out;
    }

    /**
     * Narrow a files query to the search corpus: types 1/2, minus any file
     * column an administrator excluded from attachment search.
     *
     * @param \Illuminate\Database\Eloquent\Builder<File> $query
     */
    public static function scopeBusinessFiles($query, string $tableName): void
    {
        $query->whereIn('file_type', [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT]);

        $excluded = self::excludedColumnIdsFor($tableName);
        if (empty($excluded)) {
            return;
        }
        // A document-tab file belongs to no column, so it must survive this.
        $query->where(function ($query) use ($excluded) {
            $query->whereNull('custom_column_id')->orWhereNotIn('custom_column_id', $excluded);
        });
    }

    /**
     * Columns whose attachments stay out of the corpus. Absent option means
     * searched: the feature is on by default, and only an explicit switch
     * takes a column out.
     *
     * @param iterable<object> $columns
     * @return array<int,int>
     */
    public static function excludedColumnIds($columns): array
    {
        $ids = [];
        foreach ($columns as $column) {
            if (boolval($column->getOption('attachment_search_excluded'))) {
                $ids[] = (int) $column->id;
            }
        }

        return $ids;
    }

    /** @return array<int,int> */
    private static function excludedColumnIdsFor(string $tableName): array
    {
        try {
            $table = CustomTable::getEloquent($tableName);
            return $table ? self::excludedColumnIds($table->custom_columns) : [];
        } catch (\Throwable $e) {
            // An unreadable definition must not quietly drop attachments that
            // are supposed to be searchable.
            Log::warning('[Meili] attachment column exclusion lookup failed: ' . $e->getMessage(), [
                'table' => $tableName,
            ]);
            return [];
        }
    }

    /**
     * The reusable-cache decision is deliberately kept independent of storage
     * and the database so it has a small, deterministic unit-test boundary.
     */
    public static function canReuse(?MeiliAttachmentText $cached, ?string $sourceHash): bool
    {
        return $cached !== null
            && self::canReuseIdentity($cached->file_hash === null ? null : (string) $cached->file_hash, $sourceHash)
            && $cached->extractor_version === self::EXTRACTOR_VERSION;
    }

    /** The identity half of canReuse(), independent of the cache row. */
    public static function canReuseIdentity(?string $cached, ?string $source): bool
    {
        return $cached !== null && $source !== null && hash_equals($cached, $source);
    }

    /**
     * Stable cache key for one stored file. Past $maxBytes nothing will be
     * parsed, but the row still needs a key that moves when the file does -
     * returning null there made canReuse() never hold, so an oversized file was
     * re-read in full on every save.
     *
     * @param mixed $disk Laravel filesystem adapter
     */
    public static function identityFor($disk, string $path, int $maxBytes): ?string
    {
        try {
            if (!$disk->exists($path)) {
                return null;
            }

            $size = (int) $disk->size($path);
            if ($size > $maxBytes) {
                return hash('sha256', 'oversize:' . $size . ':' . (int) $disk->lastModified($path));
            }

            $stream = $disk->readStream($path);
            if (!is_resource($stream)) {
                return null;
            }
            try {
                $hash = hash_init('sha256');
                while (!feof($stream)) {
                    $chunk = fread($stream, 8192);
                    if ($chunk === false) {
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

    /** Whether a record still owns any business attachment (including a file
     * whose text is unsupported or failed, because its filename is searchable). */
    public static function hasAttachmentForRecord(string $tableName, $recordId): bool
    {
        try {
            return File::query()
                ->where('parent_type', $tableName)
                ->where('parent_id', $recordId)
                ->tap(fn ($query) => self::scopeBusinessFiles($query, $tableName))
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
                ->tap(fn ($query) => self::scopeBusinessFiles($query, $tableName))
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
                ->tap(fn ($query) => self::scopeBusinessFiles($query, $tableName))
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
            $maxBytes = max(1, (int) config('meilisearch.attachment_extraction.max_bytes', 25 * 1024 * 1024));

            return self::identityFor(
                Storage::disk(config('admin.upload.disk')),
                (string) $file->path,
                $maxBytes
            );
        } catch (\Throwable $e) {
            return null;
        }
    }
}
