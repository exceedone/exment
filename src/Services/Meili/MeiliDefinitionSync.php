<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Exceedone\Exment\Model\CustomColumn;
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
        if ($model->wasRecentlyCreated || !$model->exists) {
            return true;
        }
        if (is_object($model) && method_exists($model, 'trashed') && $model->trashed()) {
            return true;
        }

        $attrs = $model instanceof CustomTable
            ? ['table_name', 'table_view_name', 'options']
            : ['column_name', 'column_type', 'options'];

        // isDirty covers listeners firing before syncOriginal(); wasChanged
        // covers the normal post-save state.
        return $model->isDirty($attrs) || $model->wasChanged($attrs);
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

        if ($model instanceof CustomColumn) {
            return $model->custom_table->table_name ?? null;
        }

        return null;
    }
}
