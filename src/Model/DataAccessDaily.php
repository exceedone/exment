<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * The reads and the writes of the data of a day (ISS02905): a user, a table, a kind, from where,
 * how many. Made each day from data_access_logs (DataAccessLogs::summarize), kept longer.
 *
 * @property int $id
 * @property \Carbon\Carbon $date
 * @property int|null $base_user_id
 * @property int $custom_table_id
 * @property string $kind
 * @property string $via
 * @property int $count
 */
class DataAccessDaily extends Model
{
    protected $table = 'data_access_daily';

    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = [
        'date' => 'date',
        'count' => 'integer',
    ];
}
