<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomTable;

/**
 * Catch table/column DEFINITION changes (CustomTable / CustomColumn) -> reindex
 * the affected table: enabling/disabling search, adding/removing freeword
 * columns, deleting tables/columns all invalidate the indexed documents.
 *
 * Sits between the two neighbours it is easily confused with:
 *  - MeiliSync   : record DATA changed  -> sync one document.
 *  - MeiliConfig : system SETTINGS      -> push into config('meilisearch.*').
 */
class MeiliDefinitionSync
{
    /**
     * Handle a config model that was just saved/deleted: if relevant, dispatch a reindex job.
     *
     * @param  mixed  $model
     */
    public static function handle($model): void
    {
        if (!boolval(config('meilisearch.realtime_sync'))) {
            return;
        }

        $tableName = self::resolveTableName($model);
        if ($tableName === null) {
            return;
        }

        if (!self::isRelevantChange($model)) {
            return;
        }

        // Delayed + unique, so a screen saving many columns at once collapses to
        // one job that reads the committed state instead of racing it.
        ReindexMeiliTableJob::dispatchUnlessBlocking($tableName);

        // Which columns make up a table's label is a CustomColumnMulti, so the
        // change lands here rather than on a column. Documents of OTHER tables
        // store this table's label for their linked records, so they go stale too.
        if ($model instanceof CustomColumnMulti) {
            self::reindexReferencing($model->custom_table);

            // Moved to another table: the table it left keeps the old label.
            // getOriginal still holds the pre-save value here, as Eloquent fires
            // "saved" before syncOriginal() (same as MeiliFilterSetting).
            $former = $model->getOriginal('custom_table_id');
            if ($former !== null && (int) $former !== (int) $model->custom_table_id) {
                $formerTable = CustomTable::getEloquent($former);
                if ($formerTable) {
                    ReindexMeiliTableJob::dispatchUnlessBlocking($formerTable->table_name);
                    self::reindexReferencing($formerTable);
                }
            }
        }
    }

    /**
     * Reindex the indexable tables holding a linked label of $refTable.
     *
     * @param  mixed  $refTable
     */
    protected static function reindexReferencing($refTable): void
    {
        if (!$refTable) {
            return;
        }

        foreach (MeiliSync::referencingColumns($refTable) as [$table]) {
            ReindexMeiliTableJob::dispatchUnlessBlocking($table->table_name);
        }
    }

    /**
     * Skip reindexing when the save cannot affect the index (e.g. reordering
     * columns, editing a view name). Deletes, restores and new models always
     * count; otherwise at least one search-relevant attribute must have
     * changed. Option changes (search_enabled, freeword_search, select
     * choices...) live in the json 'options' attribute, so any options change
     * dispatches — over-triggering is acceptable, missing a change is not.
     *
     * @param  mixed  $model
     */
    public static function isRelevantChange($model): bool
    {
        // Of the multisettings only the label definition reaches the documents;
        // uniques, compare and share never do. Checked before the blanket rules
        // below so creating one of those does not reindex a table for nothing.
        if ($model instanceof CustomColumnMulti && !self::isTableLabelSetting($model)) {
            return false;
        }

        if ($model->wasRecentlyCreated || !$model->exists) {
            return true;
        }
        if (is_object($model) && method_exists($model, 'trashed') && $model->trashed()) {
            return true;
        }

        if ($model instanceof CustomColumnMulti) {
            $attrs = ['custom_table_id', 'multisetting_type', 'options'];
        } else {
            $attrs = $model instanceof CustomTable
                ? ['table_name', 'table_view_name', 'options']
                : ['column_name', 'column_type', 'options'];
        }

        // isDirty covers listeners firing before syncOriginal(); wasChanged
        // covers the normal post-save state.
        return $model->isDirty($attrs) || $model->wasChanged($attrs);
    }

    /**
     * Whether this multisetting is (or was) the table's label definition.
     *
     * The original value counts too: switching a row away from TABLE_LABELS, or
     * moving it to another table, leaves the old table's documents stale.
     */
    protected static function isTableLabelSetting(CustomColumnMulti $model): bool
    {
        $values = [$model->multisetting_type, $model->getOriginal('multisetting_type')];

        foreach ($values as $value) {
            if ($value !== null && (string) $value === MultisettingType::TABLE_LABELS) {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the table_name to reindex from the config model. Returns null if not relevant.
     *
     * @param  mixed  $model
     */
    public static function resolveTableName($model): ?string
    {
        if ($model instanceof CustomTable) {
            return $model->table_name;
        }

        if ($model instanceof CustomColumn || $model instanceof CustomColumnMulti) {
            return $model->custom_table->table_name ?? null;
        }

        return null;
    }
}
