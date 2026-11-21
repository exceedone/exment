<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * File server: an upload in parts that is not finished yet (ISS00293).
 * The parts are kept in a local folder until the last one comes.
 *
 * @property string $id
 * @property int $workspace_id
 * @property int|null $parent_id
 * @property int $user_id
 * @property string $name
 * @property string|null $relative_path
 * @property int $total_size
 * @property int $received_size
 * @property string|null $comment
 * @property \Carbon\Carbon|null $updated_at
 */
class FileUploadSession extends Model
{
    public $incrementing = false;
    protected $keyType = 'string';
    protected $guarded = [];

    protected $casts = [
        'workspace_id' => 'integer',
        'parent_id' => 'integer',
        'user_id' => 'integer',
        'total_size' => 'integer',
        'received_size' => 'integer',
    ];
}
