<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * File server, phase 5 (ISS00379): a user follows a folder or file (or a
 * workspace, node_id null) and is told of what changes in it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $node_id
 * @property int $user_id
 */
class FileFollow extends Model
{
    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'node_id' => 'integer',
        'user_id' => 'integer',
    ];
}
