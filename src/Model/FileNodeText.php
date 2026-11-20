<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * File server, phase 4 (ISS00378): the text taken out of a file (its current
 * version), for the search.
 *
 * @property int $id
 * @property int $node_id
 * @property int|null $version_id
 * @property string|null $content
 * @property string $status
 * @property string|null $method
 * @property \Carbon\Carbon|null $updated_at
 */
class FileNodeText extends Model
{
    public const STATUS_DONE = 'done';
    public const STATUS_NONE = 'none';
    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'node_id' => 'integer',
        'version_id' => 'integer',
    ];
}
