<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Jobs\ReindexMeiliTableJob;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Configuration of columns used as filters (facets) — managed via the
 * MeiliFilterController screen. Only applied when config('meilisearch.filter.mode') = 'manual'.
 *
 * Save/delete -> auto reindex the related table (facets are baked into the document at index time).
 *
 * @property mixed $custom_table_id
 * @property mixed $column_name
 * @property mixed $filter_type
 */
class MeiliFilterSetting extends ModelBase
{
    use Traits\UseRequestSessionTrait;

    protected $fillable = ['custom_table_id', 'column_name', 'alias', 'filter_type', 'mode', 'view_label', 'order', 'enabled'];

    protected $casts = ['enabled' => 'boolean', 'order' => 'integer'];

    public function custom_table(): BelongsTo
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    protected static function boot()
    {
        parent::boot();

        // Changing filter config -> reindex that table (facets must be rebuilt in the index).
        static::saved(function ($model) {
            $model->dispatchReindex();
        });
        static::deleted(function ($model) {
            $model->dispatchReindex();
        });
    }

    /**
     * Tables already dispatched recently (table_name => unix time). The
     * settings screen saves many rows in one request; without this, N rows
     * would mean N full-table reindexes (inline on the sync queue driver).
     *
     * @var array<string,float>
     */
    protected static array $reindexDispatched = [];

    protected function dispatchReindex(): void
    {
        try {
            $table = CustomTable::getEloquent($this->custom_table_id);
            if (!$table || !class_exists(\Meilisearch\Client::class)) {
                return;
            }

            $now = microtime(true);
            $last = self::$reindexDispatched[$table->table_name] ?? null;
            if ($last !== null && ($now - $last) < 5.0) {
                return;
            }
            self::$reindexDispatched[$table->table_name] = $now;

            // afterResponse: on the sync queue driver the reindex would
            // otherwise run inline and could time out the settings screen.
            ReindexMeiliTableJob::dispatch($table->table_name)->afterResponse();
        } catch (\Throwable $e) {
            // skip if queue/meili is not configured yet.
        }
    }

}
