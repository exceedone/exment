<?php

namespace Exceedone\Exment\Model;

/**
 * File server: a version of a file (ISS00293, phase 2).
 *
 * @property int $id
 * @property int $node_id
 * @property int $version_no
 * @property string $path
 * @property int $size
 * @property string|null $mime_type
 * @property string|null $hash
 * @property string|null $comment
 * @property string|null $thumbnail_path
 * @property string|null $preview_path
 * @property string|null $rendition_status
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property FileNode|null $node
 */
class FileNodeVersion extends ModelBase
{
    public const RENDITION_DONE = 'done';
    public const RENDITION_NONE = 'none';
    public const RENDITION_FAILED = 'failed';
    // a file from people outside: made when someone inside opens it (ISS00410)
    public const RENDITION_HELD = 'held';

    protected $casts = [
        'id' => 'integer',
        'node_id' => 'integer',
        'created_user_id' => 'integer',
        'size' => 'integer',
        'version_no' => 'integer',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileNode, $this>
     */
    public function node()
    {
        return $this->belongsTo(FileNode::class, 'node_id');
    }
}
