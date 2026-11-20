<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * File server: a workspace (a "site" of SharePoint), the place of a team
 * with its own members, folders and files (ISS00293).
 *
 * @property int $id
 * @property string $suuid
 * @property string $name
 * @property string|null $description
 * @property int|null $quota_bytes
 * @property int $trash_days
 * @property int $version_limit
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $updated_at
 */
class FileWorkspace extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use SoftDeletes;

    protected $casts = [
        'id' => 'integer',
        'created_user_id' => 'integer',
        'quota_bytes' => 'integer',
        'trash_days' => 'integer',
        'version_limit' => 'integer',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FileNode, $this>
     */
    public function nodes()
    {
        return $this->hasMany(FileNode::class, 'workspace_id');
    }

    /**
     * The members: the permissions of the top of the workspace.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FilePermission, $this>
     */
    public function members()
    {
        return $this->hasMany(FilePermission::class, 'workspace_id')->whereNull('node_id');
    }

    /**
     * The bytes of all the versions of the files (the files in the trash too).
     */
    public function usedBytes(): int
    {
        return intval(FileNodeVersion::query()
            ->join('file_nodes', 'file_nodes.id', '=', 'file_node_versions.node_id')
            ->where('file_nodes.workspace_id', $this->id)
            ->sum('file_node_versions.size'));
    }
}
