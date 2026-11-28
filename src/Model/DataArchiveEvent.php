<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A step of the data archive (ISS02970): who did what to which archive, and how many rows.
 *
 * @property int $id
 * @property int|null $data_archive_id
 * @property string|null $source
 * @property string $action
 * @property int|null $rows
 * @property string|null $detail
 * @property int|null $user_id
 * @property \Carbon\Carbon|null $created_at
 */
class DataArchiveEvent extends Model
{
    // on the connection of the logs when one is set
    use Traits\UsesLogConnection;

    public const UPDATED_AT = null;

    protected $table = 'data_archive_events';

    protected $guarded = ['id'];

    protected $casts = ['rows' => 'integer'];
}
