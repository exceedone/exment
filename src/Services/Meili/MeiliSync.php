<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use Exceedone\Exment\Jobs\SyncMeiliReferencesJob;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Meili\AttachmentText\AttachmentTextCache;

/**
 * Decide and dispatch the Meilisearch sync job when an Exment record changes.
 */
class MeiliSync
{
    /**
     * Handle a model that was just saved/deleted: if valid, dispatch a sync job.
     *
     * @param  mixed  $model
     * @param  string  $action  'upsert' | 'delete'
     */
    public static function handle($model, string $action): void
    {
        // Disabled via config -> do nothing.
        if (!MeiliRuntime::realtimeSyncEnabled()) {
            return;
        }

        if (!self::shouldSync($model, $action)) {
            return;
        }

        // Never let a search-sync problem break the user's save/delete:
        // with the sync queue driver the job runs inline in this request, and
        // with async drivers dispatch itself can throw (queue store down).
        try {
            SyncMeiliDocumentJob::dispatch(
                $model->custom_table->table_name,
                $model->id,
                $action
            );
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[Meili] sync dispatch failed: ' . $e->getMessage()
            );
        }
    }

    /**
     * Documents store the labels of linked records: when a linked record's label
     * changes, re-sync the records that point to it.
     *
     * @param  mixed  $model
     */
    public static function handleLabelChange($model): void
    {
        if (!MeiliRuntime::realtimeSyncEnabled() || !($model instanceof CustomValue)) {
            return;
        }

        try {
            if (empty(self::referencingColumns($model->custom_table)) || !self::labelChanged($model)) {
                return;
            }
            SyncMeiliReferencesJob::dispatch($model->custom_table->table_name, $model->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] reference sync dispatch failed: ' . $e->getMessage());
        }
    }

    /**
     * Exment restores 1:n children with a bulk query that fires no model events.
     *
     * @param  mixed  $model
     */
    public static function handleRestoredChildren($model): void
    {
        if (!MeiliRuntime::realtimeSyncEnabled() || !($model instanceof CustomValue)) {
            return;
        }

        try {
            $limit = max(1, (int) config('meilisearch.reindex_chunk_size', 500));
            foreach (CustomRelation::getRelationsByParent($model->custom_table, RelationType::ONE_TO_MANY) as $relation) {
                $child = $relation->child_custom_table;
                if (!ExmentIndexer::isSearchable($child)) {
                    continue;
                }
                $ids = ExmentIndexer::recordsQuery($child)
                    ->where('parent_type', $model->custom_table->table_name)
                    ->where('parent_id', $model->id)
                    ->limit($limit + 1)
                    ->pluck('id');

                if ($ids->count() > $limit) {
                    ReindexMeiliTableJob::dispatchUnlessBlocking($child->table_name);
                    continue;
                }
                foreach ($ids as $id) {
                    SyncMeiliDocumentJob::dispatch($child->table_name, $id, 'upsert');
                }
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] restored children sync failed: ' . $e->getMessage());
        }
    }

    /**
     * Indexable tables whose indexed link columns (freeword or facet) point to $refTable.
     *
     * @param  CustomTable  $refTable
     * @return array<int,array{0:CustomTable,1:\Illuminate\Support\Collection<int,\Exceedone\Exment\Model\CustomColumn>}>
     */
    public static function referencingColumns($refTable): array
    {
        // Request session, not a static: the queue worker clears it between jobs.
        return System::requestSession('meili_referencing_columns_' . $refTable->id, function () use ($refTable) {
            $out = [];
            foreach (CustomTable::allRecords() as $table) {
                if (!ExmentIndexer::isIndexable($table)) {
                    continue;
                }
                $columns = collect($table->getFreewordSearchColumns())
                    ->concat(FilterConfig::equalityColumns($table))
                    ->unique('id')
                    ->filter(fn ($c) => ColumnType::isSelectTable($c->column_type)
                        && (int) ($c->select_target_table->id ?? 0) === (int) $refTable->id)
                    ->values();
                if ($columns->isNotEmpty()) {
                    $out[] = [$table, $columns];
                }
            }
            return $out;
        });
    }

    /** @var \WeakMap<CustomValue, array<string,mixed>|null>|null label source values before the update */
    protected static ?\WeakMap $labelBefore = null;

    /**
     * Called on "updating": by "saved", Exment has already re-saved and synced the original values.
     *
     * @param  mixed  $model
     */
    public static function rememberLabel($model): void
    {
        if (!MeiliRuntime::realtimeSyncEnabled() || !($model instanceof CustomValue)) {
            return;
        }

        try {
            if (empty(self::referencingColumns($model->custom_table))) {
                return;
            }
            self::$labelBefore ??= new \WeakMap();
            // Keep the first snapshot: Exment's nested re-save sees already-synced originals.
            if (!isset(self::$labelBefore[$model])) {
                self::$labelBefore[$model] = self::labelSource($model, (array) json_decode((string) $model->getRawOriginal('value'), true));
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[Meili] label snapshot failed: ' . $e->getMessage());
        }
    }

    /**
     * Whether the save changed a value the label is built from.
     */
    protected static function labelChanged(CustomValue $model): bool
    {
        if (self::$labelBefore === null || !isset(self::$labelBefore[$model])) {
            return false;
        }
        $before = self::$labelBefore[$model];
        unset(self::$labelBefore[$model]);

        return $before !== self::labelSource($model, (array) $model->value);
    }

    /**
     * Values of the columns the label is built from (as CustomValue::getBasicLabel);
     * null for a custom label format, which may use any column.
     *
     * @param  array<string,mixed>  $value
     * @return array<string,mixed>|null
     */
    protected static function labelSource(CustomValue $model, array $value): ?array
    {
        $table = $model->custom_table;
        $labelColumns = $table->getLabelColumns();
        if (is_string($labelColumns)) {
            return ['*' => $value];
        }
        $columns = empty($labelColumns) || count($labelColumns) === 0
            ? [$table->custom_columns_cache->first()]
            : $labelColumns->map(fn ($c) => \Exceedone\Exment\Model\CustomColumn::getEloquent($c->table_label_id))->all();

        $out = [];
        foreach ($columns as $column) {
            if ($column) {
                $out[$column->column_name] = array_get($value, $column->column_name);
            }
        }
        return $out;
    }

    /**
     * Sync record changes for freeword tables and attachment-owning records.
     * Deletes are always forwarded so a removed final file cannot strand a doc.
     *
     * @param  mixed  $model
     */
    public static function shouldSync($model, string $action = 'upsert'): bool
    {
        if (!($model instanceof CustomValue)) {
            return false;
        }

        $table = $model->custom_table;
        if (!$table) {
            return false;
        }
        // A deleted record may already have lost its last file by the time
        // this event fires. Deleting a missing document is harmless,
        // but a table with search off can hold none at all.
        if ($action === 'delete') {
            return ExmentIndexer::isAttachmentCapable($table);
        }
        return ExmentIndexer::isIndexable($table)
            || (ExmentIndexer::isAttachmentCapable($table)
                && AttachmentTextCache::hasAttachmentForRecord($table->table_name, $model->id));
    }
}
