<?php

namespace Exceedone\Exment\Services;

use Carbon\Carbon;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\CustomValueModelScope;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\DeletedArchive;
use Exceedone\Exment\Model\File;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * The deleted data deleted for good after the days of its table (ISS00147), by
 * exment:trash-purge every night.
 *
 * A table purges only when its option "deleted_keep_days" is more than 0. Before a row is
 * deleted for good, its value, its child rows (1:n), comments, revisions and files are written
 * to deleted_archives, and each file is copied to the archive disk: forceDelete() removes all of
 * them. When a file cannot be copied, the row stays as it is (and is tried again the next night).
 * The archives are removed after config exment.deleted_archive_keep_days (0: kept).
 */
class TrashPurge
{
    /** The option of the table: the days a deleted row is kept (empty or 0: never purged). */
    public const OPTION = 'deleted_keep_days';

    public const CHUNK_SIZE = 1000;

    /** How deep the child rows are followed. */
    public const CHILD_DEPTH = 5;

    /**
     * The tables never purged here: the users and organizations are linked from everywhere, and
     * the comments and documents go with the row they belong to.
     */
    public const EXCLUDED_TABLES = [
        SystemTableName::USER,
        SystemTableName::ORGANIZATION,
        SystemTableName::COMMENT,
        SystemTableName::DOCUMENT,
    ];

    /**
     * Whether a table may have the setting.
     */
    public static function purgeable(?CustomTable $table): bool
    {
        return !is_null($table) && !in_array($table->table_name, static::EXCLUDED_TABLES, true);
    }

    /**
     * The days a deleted row of the table is kept (0: never purged).
     */
    public static function keepDays(CustomTable $table): int
    {
        if (!static::purgeable($table)) {
            return 0;
        }
        $days = $table->getOption(static::OPTION);
        if (is_nullorempty($days) || !is_numeric($days)) {
            return 0;
        }
        return max(0, intval($days));
    }

    /**
     * The tables with days set, or the one named.
     *
     * @return Collection<int, CustomTable>
     */
    public static function tables(?string $tableName = null): Collection
    {
        return CustomTable::query()->orderBy('id')->get()->filter(function ($table) use ($tableName) {
            if (!is_nullorempty($tableName) && $table->table_name !== $tableName) {
                return false;
            }
            return static::keepDays($table) > 0;
        })->values();
    }

    /**
     * The rows deleted before this are purged.
     */
    public static function threshold(CustomTable $table, ?Carbon $now = null): Carbon
    {
        return ($now ?? Carbon::now())->copy()->subDays(static::keepDays($table));
    }

    /**
     * The deleted rows of the table older than its days.
     *
     * @return \Illuminate\Database\Eloquent\Builder<CustomValue>
     */
    public static function query(CustomTable $table, Carbon $threshold)
    {
        return getModelName($table)::withoutGlobalScope(CustomValueModelScope::class)
            ->onlyTrashed()
            ->whereNotNull('deleted_at')
            ->where('deleted_at', '<', $threshold);
    }

    /**
     * Purge the old deleted rows of a table.
     *
     * @return array{target: int, purged: int, skipped: int}
     */
    public static function purgeTable(CustomTable $table, bool $dryRun = false, ?Carbon $now = null): array
    {
        $result = ['target' => 0, 'purged' => 0, 'skipped' => 0];
        if (static::keepDays($table) <= 0) {
            return $result;
        }
        $threshold = static::threshold($table, $now);

        if ($dryRun) {
            $result['target'] = static::query($table, $threshold)->count();
            return $result;
        }

        $lastId = 0;
        do {
            $values = static::query($table, $threshold)
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(static::CHUNK_SIZE)
                ->get();
            foreach ($values as $value) {
                $lastId = $value->id;
                // a child row deleted with its parent earlier in this run
                if (!static::query($table, $threshold)->where('id', $value->id)->exists()) {
                    continue;
                }
                $result['target']++;
                if (static::purgeValue($value, $now)) {
                    $result['purged']++;
                } else {
                    $result['skipped']++;
                }
            }
        } while ($values->count() === static::CHUNK_SIZE);

        return $result;
    }

    /**
     * Archive a deleted row, then delete it for good (one by one, so that the files, the
     * children, the plugins and the search index follow as with any other delete).
     *
     * @return bool false: kept (a file could not be copied, or the delete failed)
     */
    public static function purgeValue(CustomValue $value, ?Carbon $now = null): bool
    {
        $now = $now ?? Carbon::now();
        $table = $value->custom_table;
        $label = $table->table_name . ':' . $value->id;

        try {
            $alive = false;
            $children = static::children($value, 1, $alive);
            if ($alive) {
                Log::warning("[Exment] trash purge: {$label} kept, it has child rows not deleted");
                return false;
            }
            $files = static::files($value, $children);
        } catch (\Throwable $e) {
            Log::error("[Exment] trash purge: {$label} kept, it could not be read: " . $e->getMessage());
            return false;
        }

        $files = static::copyFiles($files, static::archiveDir($table, $value, $now));
        if (is_null($files)) {
            Log::warning("[Exment] trash purge: {$label} kept, a file could not be copied to the archive");
            return false;
        }

        try {
            DB::transaction(function () use ($value, $table, $children, $files, $now) {
                DeletedArchive::create([
                    'custom_table_id' => $table->id,
                    'custom_table_name' => $table->table_name,
                    'value_id' => $value->id,
                    'value' => static::row($value),
                    'children' => $children,
                    'comments' => static::comments($value),
                    'revisions' => static::revisions($value),
                    'files' => $files,
                    'deleted_at' => $value->deleted_at,
                    'archived_at' => $now,
                    'archived_by' => \Exment::getUserId(),
                    'purge_after' => static::purgeAfter($now),
                ]);

                // no mails for each row of the night
                $value->saved_notify(false);
                $value->forceDelete();
            });
        } catch (\Throwable $e) {
            // the copies are removed only when the files of the row are still there
            $source = static::sourceDisk();
            $kept = collect($files)->every(function ($file) use ($source) {
                return !empty($file['missing']) || $source->exists(path_join($file['local_dirname'], $file['local_filename']));
            });
            if ($kept) {
                static::removeFiles($files);
            }
            Log::error("[Exment] trash purge: {$label} kept, it could not be deleted: " . $e->getMessage());
            return false;
        }
        return true;
    }

    /**
     * When an archive made now may be removed (null: kept).
     */
    public static function purgeAfter(Carbon $now): ?Carbon
    {
        $days = intval(config('exment.deleted_archive_keep_days', 365));
        return $days > 0 ? $now->copy()->addDays($days)->startOfDay() : null;
    }

    /**
     * Remove the archives (and their files) past their purge_after.
     */
    public static function removeExpired(bool $dryRun = false, ?Carbon $now = null): int
    {
        $today = ($now ?? Carbon::now())->copy()->startOfDay();
        $query = DeletedArchive::query()->whereNotNull('purge_after')->where('purge_after', '<=', $today->toDateString());
        if ($dryRun) {
            return $query->count();
        }

        $removed = 0;
        do {
            $archives = (clone $query)->orderBy('id')->limit(static::CHUNK_SIZE)->get();
            foreach ($archives as $archive) {
                static::removeFiles($archive->files ?? []);
                $archive->delete();
                $removed++;
            }
        } while ($archives->count() === static::CHUNK_SIZE);
        return $removed;
    }

    // the archive --------------------------------------------------

    /**
     * The value and the system columns of a row.
     *
     * @return array<string, mixed>
     */
    public static function row(CustomValue $value): array
    {
        $json = $value->value;
        if (!is_array($json)) {
            $json = json_decode(strval($json), true) ?: [];
        }
        return [
            'id' => $value->id,
            'suuid' => $value->suuid,
            'parent_id' => $value->parent_id,
            'parent_type' => $value->parent_type,
            'value' => $json,
            'created_at' => static::date($value->created_at),
            'updated_at' => static::date($value->updated_at),
            'deleted_at' => static::date($value->deleted_at),
            'created_user_id' => $value->created_user_id,
            'updated_user_id' => $value->updated_user_id,
            'deleted_user_id' => $value->deleted_user_id,
        ];
    }

    /**
     * The child rows (1:n) of a row, with their comments, revisions and own children.
     *
     * @param bool $alive set true when a child row is not deleted
     * @return array<int, array<string, mixed>>
     */
    public static function children(CustomValue $value, int $depth, bool &$alive): array
    {
        $result = [];
        if ($depth > static::CHILD_DEPTH) {
            return $result;
        }
        foreach (CustomRelation::getRelationsByParent($value->custom_table, RelationType::ONE_TO_MANY) as $relation) {
            $query = $value->getChildrenValues($relation, true);
            if (is_null($query)) {
                continue;
            }
            $children = $query->withoutGlobalScope(CustomValueModelScope::class)->withTrashed()->orderBy('id')->get();
            foreach ($children as $child) {
                if (is_null($child->deleted_at)) {
                    $alive = true;
                }
                $result[] = array_merge(['table_name' => $child->custom_table->table_name], static::row($child), [
                    'comments' => static::comments($child),
                    'revisions' => static::revisions($child),
                    'children' => static::children($child, $depth + 1, $alive),
                ]);
            }
        }
        return $result;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function comments(CustomValue $value): array
    {
        return $value->getComments()->map(function ($comment) {
            /** @var CustomValue $comment */
            return static::row($comment);
        })->values()->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function revisions(CustomValue $value): array
    {
        return $value->revisionHistory()->orderBy('id')->get()->map(function ($revision) {
            return $revision->getAttributes();
        })->values()->all();
    }

    /**
     * The rows of the files of a row and of its children (with the document of each one).
     *
     * @param array<int, array<string, mixed>> $children
     * @return array<int, array<string, mixed>>
     */
    public static function files(CustomValue $value, array $children): array
    {
        $owners = [[$value->custom_table->table_name, $value->id]];
        $walk = function (array $rows) use (&$walk, &$owners) {
            foreach ($rows as $row) {
                $owners[] = [$row['table_name'], $row['id']];
                $walk($row['children'] ?? []);
            }
        };
        $walk($children);

        $result = [];
        foreach ($owners as list($tableName, $id)) {
            $documents = [];
            foreach (getModelName(SystemTableName::DOCUMENT)::withoutGlobalScope(CustomValueModelScope::class)
                ->where('parent_type', $tableName)->where('parent_id', $id)->get() as $document) {
                $uuid = array_get($document->value, 'file_uuid');
                if (!is_nullorempty($uuid)) {
                    $documents[$uuid] = static::row($document);
                }
            }

            $files = File::where('parent_type', $tableName)->where('parent_id', $id)->orderBy('created_at')->get();
            foreach ($files as $file) {
                $result[$file->uuid] = [
                    'uuid' => $file->uuid,
                    'file_type' => $file->file_type,
                    'filename' => $file->filename,
                    'local_dirname' => $file->local_dirname,
                    'local_filename' => $file->local_filename,
                    'parent_type' => $file->parent_type,
                    'parent_id' => $file->parent_id,
                    'custom_column_id' => $file->custom_column_id,
                    'custom_form_column_id' => $file->custom_form_column_id,
                    'options' => $file->options,
                    'created_user_id' => $file->created_user_id,
                    'created_at' => static::date($file->created_at),
                    'updated_at' => static::date($file->updated_at),
                    'document' => $documents[$file->uuid] ?? null,
                ];
            }
        }
        return array_values($result);
    }

    /**
     * Copy the files to the archive disk.
     *
     * @param array<int, array<string, mixed>> $files
     * @return array<int, array<string, mixed>>|null the files with archive_disk and archive_path, null: a copy failed (none is kept)
     */
    public static function copyFiles(array $files, string $dir): ?array
    {
        $diskName = static::archiveDiskName();
        $done = [];
        try {
            $source = static::sourceDisk();
            $target = Storage::disk($diskName);
            foreach ($files as $index => $file) {
                $path = path_join($file['local_dirname'], $file['local_filename']);
                $file['archive_disk'] = $diskName;
                $file['archive_path'] = null;
                $file['missing'] = false;
                if (!$source->exists($path)) {
                    // nothing to copy: the row of the file is kept in the archive
                    $file['missing'] = true;
                    $files[$index] = $file;
                    continue;
                }
                $archivePath = path_join($dir, $file['uuid'] . '_' . basename(strval($file['local_filename'])));
                $stream = $source->readStream($path);
                if (!is_resource($stream)) {
                    throw new \RuntimeException("the file could not be read: {$path}");
                }
                try {
                    $written = $target->writeStream($archivePath, $stream);
                } finally {
                    try {
                        fclose($stream);
                    } catch (\Throwable $e) {
                        // closed by the disk already
                    }
                }
                if ($written === false || !$target->exists($archivePath)) {
                    throw new \RuntimeException("the file could not be written: {$archivePath}");
                }
                $file['archive_path'] = $archivePath;
                $done[] = $file;
                $files[$index] = $file;
            }
        } catch (\Throwable $e) {
            Log::warning('[Exment] trash purge: ' . $e->getMessage());
            static::removeFiles($done);
            return null;
        }
        return $files;
    }

    /**
     * Remove the copies of the files of an archive.
     *
     * @param array<int, array<string, mixed>> $files
     */
    public static function removeFiles(array $files): void
    {
        $dirs = [];
        foreach ($files as $file) {
            $path = $file['archive_path'] ?? null;
            if (is_nullorempty($path)) {
                continue;
            }
            try {
                $disk = Storage::disk($file['archive_disk'] ?? static::archiveDiskName());
                $disk->delete($path);
                $dirs[$file['archive_disk'] ?? static::archiveDiskName()][dirname($path)] = true;
            } catch (\Throwable $e) {
                Log::warning('[Exment] trash purge: the archived file could not be removed: ' . $path . ' ' . $e->getMessage());
            }
        }
        // the folder of the archive, when it is empty
        foreach ($dirs as $diskName => $paths) {
            foreach (array_keys($paths) as $dir) {
                try {
                    $disk = Storage::disk($diskName);
                    if (count($disk->allFiles($dir)) === 0) {
                        $disk->deleteDirectory($dir);
                    }
                } catch (\Throwable $e) {
                    // only a folder
                }
            }
        }
    }

    /**
     * The disk of the archived files (config exment.deleted_archive_disk, else the disk of the attachments).
     */
    public static function archiveDiskName(): string
    {
        $name = config('exment.deleted_archive_disk');
        if (is_nullorempty($name)) {
            $name = config('admin.upload.disk') ?: Define::DISKNAME_ADMIN;
        }
        return strval($name);
    }

    /**
     * The folder of the archive of a row.
     */
    public static function archiveDir(CustomTable $table, CustomValue $value, Carbon $now): string
    {
        $folder = trim(strval(config('exment.deleted_archive_folder', 'archives/deleted')), '/');
        if ($folder === '') {
            $folder = 'archives/deleted';
        }
        return path_join($folder, $table->table_name, $value->id . '_' . $now->format('YmdHis') . '_' . substr(md5(uniqid('', true)), 0, 6));
    }

    /**
     * @return \Illuminate\Contracts\Filesystem\Filesystem
     */
    protected static function sourceDisk()
    {
        return Storage::disk(config('admin.upload.disk'));
    }

    /**
     * @param mixed $value
     */
    protected static function date($value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        return is_nullorempty($value) ? null : strval($value);
    }
}
