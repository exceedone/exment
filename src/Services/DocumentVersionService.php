<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\DocumentVersion;
use Exceedone\Exment\Model\File as ExmentFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Replacing an attachment of a record keeps the file it had as an older
 * version (table document_versions); the list of attachments still shows
 * one row per attachment, with the newest file.
 *
 * - The number of the newest version is the number of older versions + 1.
 * - At most config('exment.document_version_limit') older versions are kept
 *   (10 by default; 0 keeps none): the oldest ones are deleted, files too.
 * - The older files keep the record as their parent, so who may download
 *   them is decided as for the newest one.
 * - Deleting the attachment deletes its older versions too.
 */
class DocumentVersionService
{
    /**
     * The document (attachment) of the record, or null.
     *
     * @param CustomValue $custom_value
     * @param int|string $document_id
     */
    public static function findDocument(CustomValue $custom_value, $document_id): ?CustomValue
    {
        $document = getModelName(SystemTableName::DOCUMENT)::query()
            ->where('id', $document_id)
            ->where('parent_id', $custom_value->id)
            ->where('parent_type', $custom_value->custom_table_name)
            ->first();
        return $document instanceof CustomValue ? $document : null;
    }

    /**
     * Put a new file in the place of the attachment's file. The file it had becomes an older version.
     */
    public static function replace(CustomValue $custom_value, CustomValue $document, UploadedFile $httpfile, bool $prune = true): CustomValue
    {
        $custom_table = $custom_value->custom_table;
        $filename = strval($httpfile->getClientOriginalName());
        $old_uuid = strval($document->getValue('file_uuid', true));
        $old_name = strval($document->getValue('document_name'));
        $old_file = ExmentFile::getData($old_uuid);

        // store the new file first: if the database part fails, only an unused file is left
        $file = ExmentFile::storeAs(FileType::CUSTOM_VALUE_DOCUMENT, $httpfile, $custom_table->table_name, $filename)
            ->saveCustomValue($custom_value->id, null, $custom_table);

        DB::transaction(function () use ($document, $file, $filename, $old_uuid, $old_name, $old_file) {
            $version = new DocumentVersion([
                'document_id' => $document->id,
                'version_no' => static::olderCount($document->id) + 1,
                'file_uuid' => $old_uuid,
                'document_name' => $old_name,
                'created_user_id' => $old_file->created_user_id ?? $document->created_user_id,
            ]);
            // when the older file was put
            $version->created_at = $old_file->created_at ?? $document->created_at;
            $version->save();

            $document->setValue([
                'file_uuid' => $file->uuid,
                'document_name' => $filename,
            ]);
            $document->save();
        });

        // the older versions beyond the limit go only when the user could delete the attachment
        // (else replacing would be a way to delete the first file)
        if ($prune) {
            static::prune($document->id);
        }

        return $document;
    }

    /**
     * Keep a file already stored as the newest older version of the attachment,
     * without changing the attachment (for a plugin that saves the new content
     * into the same file, as an editor does, and stores what it was itself).
     * The file should have the record as its parent, as attachments do.
     *
     * @param int|null $created_user_id who made that content (default: the uploader of the file)
     */
    public static function addOlderVersion(CustomValue $document, ExmentFile $older, ?int $created_user_id = null): DocumentVersion
    {
        $version = DB::transaction(function () use ($document, $older, $created_user_id) {
            $version = new DocumentVersion([
                'document_id' => $document->id,
                'version_no' => static::olderCount($document->id) + 1,
                'file_uuid' => $older->uuid,
                'document_name' => strval($older->filename ?? $document->getValue('document_name')),
                'created_user_id' => $created_user_id ?? $older->created_user_id,
            ]);
            $version->save();
            return $version;
        });
        static::prune($document->id);
        return $version;
    }

    /**
     * The older versions of an attachment, the newest first.
     *
     * @return \Illuminate\Support\Collection<int, DocumentVersion>
     */
    public static function versions(int $document_id)
    {
        return DocumentVersion::query()
            ->where('document_id', $document_id)
            ->orderByDesc('version_no')
            ->get();
    }

    /**
     * The number of the newest version of each attachment, in one query (the list of attachments).
     *
     * @param array<int|string> $document_ids
     * @return array<int, int> document id => version number (1 when it was never replaced)
     */
    public static function currentNumbers(array $document_ids): array
    {
        $numbers = [];
        foreach ($document_ids as $id) {
            $numbers[intval($id)] = 1;
        }
        if (count($numbers) === 0) {
            return $numbers;
        }
        $rows = DocumentVersion::query()
            ->whereIn('document_id', array_keys($numbers))
            ->groupBy('document_id')
            ->select('document_id', DB::raw('MAX(version_no) as max_no'))
            ->get();
        foreach ($rows as $row) {
            $numbers[intval($row->document_id)] = intval($row->getAttribute('max_no')) + 1;
        }
        return $numbers;
    }

    /**
     * Delete the older versions of attachments (their files too): the attachments were deleted.
     *
     * @param array<int|string> $document_ids
     */
    public static function deleteFor(array $document_ids): void
    {
        if (count($document_ids) === 0) {
            return;
        }
        $versions = DocumentVersion::query()->whereIn('document_id', $document_ids)->get();
        foreach ($versions as $version) {
            static::deleteVersion($version);
        }
    }

    /**
     * Forget the older version of a file deleted by itself (from the list of
     * the older versions, or by the API): its row would point at nothing.
     */
    public static function forgetFile(string $file_uuid): void
    {
        if ($file_uuid === '' || !static::available()) {
            return;
        }
        DocumentVersion::query()->where('file_uuid', $file_uuid)->delete();
    }

    protected static function olderCount(int $document_id): int
    {
        return intval(DocumentVersion::query()->where('document_id', $document_id)->max('version_no'));
    }

    /**
     * Keep at most the limit of older versions (the newest ones).
     */
    protected static function prune(int $document_id): void
    {
        $limit = max(0, intval(config('exment.document_version_limit', 10)));
        // the versions beyond the limit (an offset needs a limit in MySQL)
        $old = DocumentVersion::query()
            ->where('document_id', $document_id)
            ->orderByDesc('version_no')
            ->skip($limit)
            ->take(1000000)
            ->get();
        foreach ($old as $version) {
            static::deleteVersion($version);
        }
    }

    protected static function deleteVersion(DocumentVersion $version): void
    {
        $file = ExmentFile::getData($version->file_uuid);
        if (isset($file)) {
            ExmentFile::deleteFileInfo($file);
        }
        $version->delete();
    }

    /**
     * Whether the table keeps the history (the documents are a custom table; it exists after the migration).
     */
    public static function available(): bool
    {
        if (static::$available === null) {
            static::$available = \Schema::hasTable('document_versions') && CustomTable::getEloquent(SystemTableName::DOCUMENT) !== null;
        }
        return static::$available;
    }

    /** @var bool|null per request */
    protected static $available = null;
}
