<?php

namespace Exceedone\Exment\Model;

/**
 * File server: who may do what in a workspace, or in a folder or file that
 * has its own permissions (ISS00293).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $node_id
 * @property string $target_type user, organization
 * @property int $target_id
 * @property int $level Services\FileServer\Level
 */
class FilePermission extends ModelBase
{
    public const TARGET_USER = 'user';
    public const TARGET_ORGANIZATION = 'organization';

    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'node_id' => 'integer',
        'target_id' => 'integer',
        'level' => 'integer',
    ];
}
