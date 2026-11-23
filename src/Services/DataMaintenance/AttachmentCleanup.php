<?php

namespace Exceedone\Exment\Services\DataMaintenance;

use Carbon\Carbon;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DocumentVersion;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Services\DocumentVersionService;
use ExmentAdminCore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Data maintenance: delete the attachments of one table at once (#1829).
 *
 * - The records stay; only their files go.
 * - "document": the attachments of the records (the attachment list of a
 *   record), with their older versions (document_versions).
 * - "column": the files of the file and image columns. The record no longer
 *   points at the file (its value is emptied), without notifying anyone and
 *   without changing who updated it last.
 * - $before: only the files added before that day (files.created_at). An
 *   attachment whose current file is newer keeps it, but loses its older
 *   versions that are older than that day.
 * - The records in the trash are included: their files fill the disk too.
 * - The data change history (revisions) is kept: it has the names of the
 *   files, not the files.
 * - Not touched: the images of the rich text columns and the comments.
 */
class AttachmentCleanup
{
    public const KIND_DOCUMENT = 'document';
    public const KIND_COLUMN = 'column';

    public const KINDS = [self::KIND_DOCUMENT, self::KIND_COLUMN];

    /** Above this number of files, the size is counted for the first ones only. */
    public const SIZE_LIMIT = 5000;

    protected const CHUNK = 200;

    protected CustomTable $custom_table;

    /** @var string[] */
    protected array $kinds;

    protected ?Carbon $before;

    /**
     * @param string[] $kinds
     */
    public function __construct(CustomTable $custom_table, array $kinds, ?Carbon $before = null)
    {
        $this->custom_table = $custom_table;
        $this->kinds = array_values(array_intersect(static::KINDS, $kinds));
        $this->before = isset($before) ? $before->copy()->startOfDay() : null;
    }

    /**
     * @return string[]
     */
    public function kinds(): array
    {
        return $this->kinds;
    }

    /**
     * What would be deleted.
     *
     * @return array{documents: int, versions: int, column_files: int, records: int, files: int, bytes: int, bytes_partial: bool}
     */
    public function summary(): array
    {
        $result = ['documents' => 0, 'versions' => 0, 'column_files' => 0, 'records' => 0, 'files' => 0, 'bytes' => 0, 'bytes_partial' => false];
        $records = [];
        $sized = 0;
        foreach ($this->items() as $item) {
            $result[$item['count']]++;
            $records[$item['record_id']] = true;
            $result['files']++;
            if (!isset($item['file'])) {
                continue;
            }
            if ($sized >= static::SIZE_LIMIT) {
                $result['bytes_partial'] = true;
                continue;
            }
            $sized++;
            $result['bytes'] += static::fileSize($item['file']);
        }
        $result['records'] = count($records);
        return $result;
    }

    /**
     * Delete them. The operation log keeps what was done.
     *
     * @return array{documents: int, versions: int, column_files: int, records: int, files: int, bytes: int, bytes_partial: bool}
     */
    public function execute(?int $user_id = null): array
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }
        $result = ['documents' => 0, 'versions' => 0, 'column_files' => 0, 'records' => 0, 'files' => 0, 'bytes' => 0, 'bytes_partial' => false];
        $records = [];
        // collect first: the deletes would move the chunks
        $items = iterator_to_array($this->items(), false);
        foreach ($items as $item) {
            if (isset($item['file'])) {
                $result['bytes'] += static::fileSize($item['file']);
            }
            switch ($item['type']) {
                case 'document':
                    $this->deleteDocument($item);
                    break;
                case 'version':
                    $this->deleteVersion($item);
                    break;
                case 'column':
                    $this->deleteColumnFile($item);
                    break;
            }
            $result[$item['count']]++;
            $result['files']++;
            $records[$item['record_id']] = true;
        }
        $result['records'] = count($records);

        $this->log($user_id, $result);
        return $result;
    }

    /**
     * The files to delete, one by one.
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function items(): \Generator
    {
        if (in_array(static::KIND_DOCUMENT, $this->kinds, true)) {
            yield from $this->documentItems();
        }
        if (in_array(static::KIND_COLUMN, $this->kinds, true)) {
            yield from $this->columnItems();
        }
    }

    /**
     * The attachments, and the older versions of those that stay.
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function documentItems(): \Generator
    {
        $document_table = CustomTable::getEloquent(SystemTableName::DOCUMENT);
        if (!isset($document_table)) {
            return;
        }
        $versions = DocumentVersionService::available();
        $last_id = 0;
        while (true) {
            $documents = getModelName(SystemTableName::DOCUMENT)::query()
                ->where('parent_type', $this->custom_table->table_name)
                ->where('id', '>', $last_id)
                ->orderBy('id')
                ->limit(static::CHUNK)
                ->get();
            if ($documents->isEmpty()) {
                return;
            }
            $last_id = $documents->last()->id;

            $uuids = $documents->map(function ($document) {
                return strval(array_get($document->value, 'file_uuid'));
            })->filter()->all();
            $files = ExmentFile::whereIn('uuid', $uuids)->get()->keyBy('uuid');

            $staying = [];
            foreach ($documents as $document) {
                $file = $files->get(strval(array_get($document->value, 'file_uuid')));
                if ($this->matches($file)) {
                    yield ['type' => 'document', 'count' => 'documents', 'document' => $document, 'file' => $file, 'record_id' => $document->parent_id];
                } else {
                    $staying[$document->id] = $document->parent_id;
                }
            }

            if (!$versions || count($staying) === 0) {
                continue;
            }
            $olders = DocumentVersion::query()->whereIn('document_id', array_keys($staying))->orderBy('id')->get();
            $older_files = ExmentFile::whereIn('uuid', $olders->pluck('file_uuid')->all())->get()->keyBy('uuid');
            foreach ($olders as $older) {
                $file = $older_files->get($older->file_uuid);
                if (!$this->matches($file, $older->created_at)) {
                    continue;
                }
                yield ['type' => 'version', 'count' => 'versions', 'version' => $older, 'file' => $file, 'record_id' => $staying[$older->document_id]];
            }
        }
    }

    /**
     * The files of the file and image columns.
     *
     * @return \Generator<array<string, mixed>>
     */
    protected function columnItems(): \Generator
    {
        $columns = $this->custom_table->custom_columns_cache->filter(function ($custom_column) {
            return ColumnType::isAttachment($custom_column->column_type);
        })->keyBy('id');
        if ($columns->isEmpty()) {
            return;
        }

        $last_uuid = '';
        while (true) {
            $query = ExmentFile::query()
                ->where('parent_type', $this->custom_table->table_name)
                ->whereIn('custom_column_id', $columns->keys()->all())
                ->where('uuid', '>', $last_uuid)
                ->orderBy('uuid')
                ->limit(static::CHUNK);
            if (isset($this->before)) {
                $query->where('created_at', '<', $this->before);
            }
            $files = $query->get();
            if ($files->isEmpty()) {
                return;
            }
            $last_uuid = $files->last()->uuid;
            foreach ($files as $file) {
                yield ['type' => 'column', 'count' => 'column_files', 'file' => $file, 'column' => $columns->get($file->custom_column_id), 'record_id' => $file->parent_id];
            }
        }
    }

    /**
     * Whether the file is older than the day (always without a day).
     *
     * @param ExmentFile|null $file
     * @param mixed $fallback the date to use when the file is not in the database
     */
    protected function matches($file, $fallback = null): bool
    {
        if (!isset($this->before)) {
            return true;
        }
        $at = isset($file) ? $file->created_at : $fallback;
        if (!isset($at)) {
            return false;
        }
        return Carbon::parse($at)->lt($this->before);
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function deleteDocument(array $item): void
    {
        if (isset($item['file'])) {
            // the file, the attachment and its older versions (File::deleteDocumentModel)
            ExmentFile::deleteDocumentModel($item['file']);
            return;
        }
        // the file is gone already: the older versions and the attachment
        if (DocumentVersionService::available()) {
            DocumentVersionService::deleteFor([$item['document']->id]);
        }
        $item['document']->delete();
    }

    /**
     * @param array<string, mixed> $item
     */
    protected function deleteVersion(array $item): void
    {
        DocumentVersionService::deleteVersion($item['version']);
    }

    /**
     * Delete the file, and take it out of the value of the record.
     *
     * @param array<string, mixed> $item
     */
    protected function deleteColumnFile(array $item): void
    {
        /** @var ExmentFile $file */
        $file = $item['file'];
        $column = $item['column'];
        $keys = [strval($file->uuid), strval($file->path)];
        ExmentFile::deleteFileInfo($file);

        if (!isset($column) || is_nullorempty($file->parent_id)) {
            return;
        }
        $modelname = getModelName($this->custom_table);
        $record = $modelname::withTrashed()->find($file->parent_id);
        if (!isset($record)) {
            return;
        }
        $value = $record->value ?? [];
        $current = array_get($value, $column->column_name);
        if (is_nullorempty($current)) {
            return;
        }
        if (is_array($current)) {
            $new = array_values(array_filter($current, function ($v) use ($keys) {
                return !in_array(strval($v), $keys, true);
            }));
            if (count($new) === count($current)) {
                return;
            }
            $value[$column->column_name] = count($new) > 0 ? $new : null;
        } else {
            if (!in_array(strval($current), $keys, true)) {
                return;
            }
            $value[$column->column_name] = null;
        }
        // not save(): no notify, no workflow, the last updater stays
        DB::table($record->getTable())->where('id', $record->id)->update([
            'value' => json_encode($value, JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param ExmentFile $file
     */
    protected static function fileSize($file): int
    {
        try {
            return intval(Storage::disk(config('admin.upload.disk'))->size($file->path));
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /**
     * @param array<string, mixed> $result
     */
    protected function log(?int $user_id, array $result): void
    {
        $detail = [
            'table' => $this->custom_table->table_name,
            'kinds' => $this->kinds,
            'before' => isset($this->before) ? $this->before->toDateString() : null,
        ] + $result;
        Log::info('[data_maintenance] attachments deleted', ['user_id' => $user_id] + $detail);
        try {
            $request = app()->runningInConsole() ? null : request();
            OperationLogModel::create([
                'user_id' => $user_id ?? 0,
                'path' => 'data_maintenance/' . $this->custom_table->table_name . '/attachments',
                'method' => isset($request) ? substr($request->method(), 0, 10) : 'CLI',
                'ip' => isset($request) ? strval($request->getClientIp()) : '127.0.0.1',
                'input' => json_encode($detail, JSON_UNESCAPED_UNICODE),
            ]);
        } catch (\Throwable $e) {
            // the log file has it
        }
    }
}
