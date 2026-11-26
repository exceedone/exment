<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * An item of the service and the record it is (ISS00113): what keeps the sync from making a
 * record twice, and the table of the old URL to the new one.
 *
 * @property int $id
 * @property int $sync_mapping_id
 * @property string $remote_id
 * @property int|null $custom_value_id
 * @property string|null $remote_url
 * @property \Carbon\Carbon|null $remote_updated_at
 * @property \Carbon\Carbon|null $local_updated_at
 * @property string|null $hash
 * @property \Carbon\Carbon|null $remote_deleted_at
 */
class SyncLink extends Model
{
    protected $table = 'sync_links';

    protected $guarded = ['id'];

    protected $casts = [
        'remote_updated_at' => 'datetime',
        'local_updated_at' => 'datetime',
        'remote_deleted_at' => 'datetime',
    ];
}
