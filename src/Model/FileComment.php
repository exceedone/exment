<?php

namespace Exceedone\Exment\Model;

/**
 * File server, phase 5 (ISS00379): a comment on a folder or file; the users
 * called with @ are told of it.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $node_id
 * @property string $body
 * @property array<int, int>|null $mentions
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 */
class FileComment extends ModelBase
{
    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'node_id' => 'integer',
        'created_user_id' => 'integer',
        'mentions' => 'array',
    ];
}
