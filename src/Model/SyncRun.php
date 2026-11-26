<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A run of a mapping of the data sync (ISS00113), or a dry run.
 *
 * @property int $id
 * @property int $sync_mapping_id
 * @property bool $dry_run
 * @property string $trigger schedule, manual
 * @property string $status running, succeeded, partial, failed
 * @property array<string, int>|null $counts
 * @property string|null $message
 * @property string|null $cursor the cursor of the next part (while running)
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property int|null $created_user_id
 */
class SyncRun extends Model
{
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_PARTIAL = 'partial';
    public const STATUS_FAILED = 'failed';

    public const COUNTS = ['created', 'updated', 'unchanged', 'skipped', 'marked', 'deleted', 'errors'];

    public $timestamps = false;

    protected $table = 'sync_runs';

    protected $guarded = ['id'];

    protected $casts = [
        'dry_run' => 'boolean',
        'counts' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function mapping(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SyncMapping::class, 'sync_mapping_id');
    }

    public function errors(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SyncRunError::class, 'sync_run_id');
    }

    public function count(string $name): int
    {
        return intval(array_get($this->counts ?? [], $name, 0));
    }

    public function add(string $name, int $n = 1): void
    {
        $counts = $this->counts ?? array_fill_keys(static::COUNTS, 0);
        $counts[$name] = intval($counts[$name] ?? 0) + $n;
        $this->counts = $counts;
    }
}
