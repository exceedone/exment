<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A mapping of the data sync (ISS00113): a unit of the service (a kintone app, a Backlog project)
 * to a table, its fields to the columns, the direction, the interval and the rules.
 *
 * @property int $id
 * @property int $sync_connection_id
 * @property string $name
 * @property string $remote_object
 * @property string|null $remote_label
 * @property int $custom_table_id
 * @property string $direction import (export and both later)
 * @property int $interval_minutes
 * @property string $conflict_rule remote, local, newer
 * @property string $delete_rule mark, delete
 * @property array<int, array{remote: string, column: string}>|null $field_map
 * @property array<string, mixed>|null $options keep_times, limit, check_deletes
 * @property bool $enabled
 * @property string|null $cursor
 * @property \Carbon\Carbon|null $next_run_at
 * @property \Carbon\Carbon|null $last_run_at
 * @property int $failures
 * @property \Carbon\Carbon|null $deletes_checked_at
 * @property SyncConnection|null $connection
 */
class SyncMapping extends Model
{
    public const DIRECTION_IMPORT = 'import';
    public const DIRECTIONS = [self::DIRECTION_IMPORT];

    public const CONFLICT_REMOTE = 'remote';
    public const CONFLICT_LOCAL = 'local';
    public const CONFLICT_NEWER = 'newer';
    public const CONFLICT_RULES = [self::CONFLICT_REMOTE, self::CONFLICT_LOCAL, self::CONFLICT_NEWER];

    public const DELETE_MARK = 'mark';
    public const DELETE_DELETE = 'delete';
    public const DELETE_RULES = [self::DELETE_MARK, self::DELETE_DELETE];

    public const INTERVALS = [15, 30, 60, 180, 720, 1440];

    protected $table = 'sync_mappings';

    protected $guarded = ['id'];

    protected $casts = [
        'field_map' => 'array',
        'options' => 'array',
        'enabled' => 'boolean',
        'next_run_at' => 'datetime',
        'last_run_at' => 'datetime',
        'deletes_checked_at' => 'datetime',
    ];

    public function connection(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(SyncConnection::class, 'sync_connection_id');
    }

    public function customTable(): ?CustomTable
    {
        return CustomTable::getEloquent($this->custom_table_id);
    }

    /**
     * @return mixed
     */
    public function option(string $name, $default = null)
    {
        return array_get($this->options ?? [], $name, $default);
    }
}
