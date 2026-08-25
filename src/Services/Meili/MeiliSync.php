<?php

namespace Exceedone\Exment\Services\Meili;

use Exceedone\Exment\Jobs\SyncMeiliDocumentJob;
use Exceedone\Exment\Model\CustomValue;

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
        if (!boolval(config('meilisearch.realtime_sync'))) {
            return;
        }

        if (!self::shouldSync($model)) {
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
     * Only sync records of a search-enabled custom table that has freeword columns.
     *
     * @param  mixed  $model
     */
    public static function shouldSync($model): bool
    {
        if (!($model instanceof CustomValue)) {
            return false;
        }

        return ExmentIndexer::isIndexable($model->custom_table);
    }
}
