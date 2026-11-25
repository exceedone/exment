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
 * @property int|null $retention_days
 * @property string $retention_action keep, delete
 * @property bool $legal_hold
 * @property bool $watermark
 * @property int $download_level
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
        'retention_days' => 'integer',
        'legal_hold' => 'boolean',
        'watermark' => 'boolean',
        'download_level' => 'integer',
    ];

    protected static function booted(): void
    {
        // the first part of the addresses (ISS02815): the old name leads to the workspace
        static::updating(function (FileWorkspace $workspace) {
            if ($workspace->isDirty('name')) {
                \Exceedone\Exment\Services\FileServer\Paths::rememberWorkspace($workspace);
            }
        });
        static::saved(function (FileWorkspace $workspace) {
            if ($workspace->wasRecentlyCreated || $workspace->wasChanged('name')) {
                \Exceedone\Exment\Services\FileServer\Paths::forgetWorkspace($workspace);
            }
        });
    }

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
