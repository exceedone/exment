<?php

namespace Exceedone\Exment\Model;

/**
 * File server: a folder or a file of a workspace (ISS00293).
 *
 * @property int $id
 * @property string $suuid
 * @property int $workspace_id
 * @property int|null $parent_id
 * @property string $node_type
 * @property string $name
 * @property bool $has_unique_permissions
 * @property int|null $current_version_id
 * @property int $size
 * @property string|null $mime_type
 * @property int|null $checked_out_user_id
 * @property \Carbon\Carbon|null $checked_out_at
 * @property \Carbon\Carbon|null $trashed_at
 * @property int|null $trashed_user_id
 * @property int|null $trash_root_id
 * @property array<string, mixed>|null $metadata
 * @property int|null $created_user_id
 * @property int|null $updated_user_id
 * @property \Carbon\Carbon|null $updated_at
 * @property FileWorkspace|null $workspace
 * @property FileNode|null $parent
 * @property FileNodeVersion|null $current_version
 */
class FileNode extends ModelBase
{
    use Traits\AutoSUuidTrait;

    public const TYPE_FOLDER = 'folder';
    public const TYPE_FILE = 'file';

    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'parent_id' => 'integer',
        'current_version_id' => 'integer',
        'checked_out_user_id' => 'integer',
        'trashed_user_id' => 'integer',
        'trash_root_id' => 'integer',
        'created_user_id' => 'integer',
        'updated_user_id' => 'integer',
        'has_unique_permissions' => 'boolean',
        'size' => 'integer',
        'checked_out_at' => 'datetime',
        'trashed_at' => 'datetime',
        'metadata' => 'array',
    ];

    /**
     * The values of the columns are kept as they are written (not escaped), so
     * that the search in the database finds the words of them.
     *
     * @param mixed $value
     * @param int $flags
     * @return string|false
     */
    protected function asJson($value, $flags = 0)
    {
        return json_encode($value, $flags | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileWorkspace, $this>
     */
    public function workspace()
    {
        return $this->belongsTo(FileWorkspace::class, 'workspace_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileNode, $this>
     */
    public function parent()
    {
        return $this->belongsTo(FileNode::class, 'parent_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FileNode, $this>
     */
    public function children()
    {
        return $this->hasMany(FileNode::class, 'parent_id');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FileNodeVersion, $this>
     */
    public function versions()
    {
        return $this->hasMany(FileNodeVersion::class, 'node_id')->orderByDesc('version_no');
    }

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileNodeVersion, $this>
     */
    public function current_version()
    {
        return $this->belongsTo(FileNodeVersion::class, 'current_version_id');
    }

    /**
     * The permissions of this folder or file, when it has its own.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<FilePermission, $this>
     */
    public function permissions()
    {
        return $this->hasMany(FilePermission::class, 'node_id');
    }

    public function isFolder(): bool
    {
        return $this->node_type === static::TYPE_FOLDER;
    }

    public function isFile(): bool
    {
        return $this->node_type === static::TYPE_FILE;
    }

    public function isTrashed(): bool
    {
        return isset($this->trashed_at);
    }

    /**
     * The folders from the top of the workspace to this node (this node is the last).
     *
     * @return FileNode[]
     */
    public function ancestorsAndSelf(): array
    {
        $list = [];
        $node = $this;
        $guard = 0;
        while (isset($node) && $guard++ < 1000) {
            array_unshift($list, $node);
            $node = isset($node->parent_id) ? static::find($node->parent_id) : null;
        }
        return $list;
    }

    /**
     * The ids of the nodes under this one (not this one), at any depth.
     *
     * @param bool $withTrashed the nodes in the trash too
     * @return int[]
     */
    public function descendantIds(bool $withTrashed = false): array
    {
        $ids = [];
        $parents = [$this->id];
        $guard = 0;
        while (count($parents) > 0 && $guard++ < 1000) {
            $query = static::query()->whereIn('parent_id', $parents);
            if (!$withTrashed) {
                $query->whereNull('trashed_at');
            }
            $parents = $query->pluck('id')->map(function ($id) {
                return intval($id);
            })->all();
            $ids = array_merge($ids, $parents);
        }
        return $ids;
    }

    public function extension(): string
    {
        return strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
    }
}
