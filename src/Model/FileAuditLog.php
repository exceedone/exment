<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * File server, phase 5 (ISS00379): a line of the audit log, who did what to
 * which folder or file.
 *
 * @property int $id
 * @property int|null $workspace_id
 * @property int|null $node_id
 * @property string|null $node_name
 * @property int|null $user_id
 * @property int|null $link_id
 * @property string $action
 * @property string|null $detail
 * @property string|null $ip
 * @property string|null $user_agent
 * @property \Carbon\Carbon $created_at
 */
class FileAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'node_id' => 'integer',
        'user_id' => 'integer',
        'link_id' => 'integer',
        'created_at' => 'datetime',
    ];
}
