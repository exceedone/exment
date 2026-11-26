<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * An item a run of the data sync could not write, and why (ISS00113).
 *
 * @property int $id
 * @property int $sync_run_id
 * @property string|null $remote_id
 * @property string $message
 * @property \Carbon\Carbon|null $created_at
 */
class SyncRunError extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'sync_run_errors';

    protected $guarded = ['id'];

    protected $casts = [
        'created_at' => 'datetime',
    ];
}
