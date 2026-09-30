<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

use Exceedone\Exment\Enums\FileType;
use Exceedone\Exment\Jobs\ExtractAttachmentTextJob;
use Exceedone\Exment\Jobs\ForgetAttachmentTextJob;
use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\File;
use Exceedone\Exment\Services\Meili\ExmentIndexer;
use Exceedone\Exment\Services\Meili\MeiliRuntime;
use Illuminate\Support\Facades\Log;

/** Event bridge for File's upload/update/delete lifecycle. */
final class AttachmentTextSync
{
    /** @var \WeakMap<File, array{table_name:string,value_id:mixed}|null>|null */
    private static ?\WeakMap $beforeUpdate = null;

    /** @var \WeakMap<File, array{table_name:string,value_id:mixed}|null>|null */
    private static ?\WeakMap $beforeDelete = null;

    public static function rememberUpdating($model): void
    {
        if (!self::automaticSyncEnabled() || !($model instanceof File)) {
            return;
        }
        self::$beforeUpdate ??= new \WeakMap();
        if (!isset(self::$beforeUpdate[$model])) {
            self::$beforeUpdate[$model] = self::target(
                (string) $model->getRawOriginal('parent_type'),
                $model->getRawOriginal('parent_id'),
                (string) $model->getRawOriginal('file_type'),
            );
        }
    }

    public static function handleSaved($model): void
    {
        if (!self::automaticSyncEnabled() || !($model instanceof File)) {
            return;
        }
        $current = self::targetForFile($model);
        $previous = self::$beforeUpdate !== null && isset(self::$beforeUpdate[$model])
            ? self::$beforeUpdate[$model]
            : null;
        if (self::$beforeUpdate !== null) {
            unset(self::$beforeUpdate[$model]);
        }

        // Moving a file between records must remove its text from the old
        // record as well. The cache itself stays because it is keyed by UUID.
        if ($previous !== null && $previous !== $current) {
            self::syncRecord($previous);
        }
        if ($current === null) {
            return;
        }

        if (MeiliRuntime::queueUsesSyncDriver()) {
            self::warnAdminSyncDriver();
        }
        try {
            ExtractAttachmentTextJob::dispatch((string) $model->uuid, $current['table_name'], $current['value_id']);
        } catch (\Throwable $e) {
            Log::warning('[Meili] attachment extraction dispatch failed: ' . $e->getMessage(), [
                'file_uuid' => $model->uuid,
            ]);
        }
    }

    public static function rememberDeleting($model): void
    {
        if (!self::automaticSyncEnabled() || !($model instanceof File)) {
            return;
        }
        self::$beforeDelete ??= new \WeakMap();
        self::$beforeDelete[$model] = self::targetForFile($model);
    }

    public static function handleDeleted($model): void
    {
        if (!self::automaticSyncEnabled() || !($model instanceof File)) {
            return;
        }
        $target = self::$beforeDelete !== null && isset(self::$beforeDelete[$model])
            ? self::$beforeDelete[$model]
            : self::targetForFile($model);
        if (self::$beforeDelete !== null) {
            unset(self::$beforeDelete[$model]);
        }
        if ($target === null) {
            return;
        }

        try {
            ForgetAttachmentTextJob::dispatch((string) $model->uuid, $target['table_name'], $target['value_id']);
        } catch (\Throwable $e) {
            Log::warning('[Meili] attachment cache-delete dispatch failed: ' . $e->getMessage(), [
                'file_uuid' => $model->uuid,
            ]);
        }
    }

    /**
     * Resolve an indexable business-record target, or null for all other file
     * types (avatars, system/public-form assets) and non-searchable tables.
     *
     * @return array{table_name:string,value_id:mixed}|null
     */
    public static function targetForFile(File $file): ?array
    {
        return self::target((string) $file->parent_type, $file->parent_id, (string) $file->file_type);
    }

    /** @return array{table_name:string,value_id:mixed}|null */
    private static function target(string $tableName, $valueId, string $fileType): ?array
    {
        if (!in_array($fileType, [FileType::CUSTOM_VALUE_COLUMN, FileType::CUSTOM_VALUE_DOCUMENT], true)
            || trim($tableName) === ''
            || $valueId === null
            || $valueId === '') {
            return null;
        }
        try {
            $table = CustomTable::getEloquent($tableName);
            if (!ExmentIndexer::isAttachmentCapable($table)) {
                return null;
            }
            return ['table_name' => $table->table_name, 'value_id' => $valueId];
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** @param array{table_name:string,value_id:mixed} $target */
    private static function syncRecord(array $target): void
    {
        try {
            SyncMeiliDocumentJob::dispatch($target['table_name'], $target['value_id'], 'upsert');
        } catch (\Throwable $e) {
            Log::warning('[Meili] old attachment owner sync dispatch failed: ' . $e->getMessage());
        }
    }

    private static function automaticSyncEnabled(): bool
    {
        return MeiliRuntime::realtimeSyncEnabled() && class_exists(\Meilisearch\Client::class);
    }

    private static function warnAdminSyncDriver(): void
    {
        try {
            if (!app()->runningInConsole() && function_exists('admin_warning')) {
                admin_warning(exmtrans('search.attachment_sync_queue_skipped'));
            }
        } catch (\Throwable $e) {
            // The notification must never interrupt an upload.
        }
    }
}
