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
     * Tables already dispatched recently (table_name => unix time). Collapses
     * bursts (e.g. a settings screen saving many columns in one request) into
     * a single reindex; a short window is used instead of a per-request flag
     * so long-running processes (queue workers) never suppress a real change.
     *
     * @var array<string,float>
     */
    protected static array $dispatched = [];

    /** Seconds during which repeated dispatches for a table are collapsed. */
    protected const DEDUP_WINDOW = 5.0;

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

        $now = microtime(true);
        if (isset(self::$dispatched[$tableName]) && ($now - self::$dispatched[$tableName]) < self::DEDUP_WINDOW) {
            return;
        }
        self::$dispatched[$tableName] = $now;

        // Queued on purpose, NOT ->afterResponse(): afterResponse() routes
        // through Dispatcher::dispatchSync(), which forces onConnection('sync')
        // and runs the whole reindex inline in the web process on every driver -
        // where PHP's max_execution_time can kill it after the documents have
        // already been deleted. A dispatch failure must never break the save.
        try {
            ReindexMeiliTableJob::dispatch($tableName);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning(
                '[Meili] reindex dispatch failed: ' . $e->getMessage()
            );
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
