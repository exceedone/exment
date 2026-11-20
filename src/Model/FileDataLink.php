<?php

namespace Exceedone\Exment\Model;

/**
 * File server, phase 4 (ISS00378): a folder or file linked to a data of
 * Exment (a customer, a project, a contract...). The data shows its files;
 * the file shows its data.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int $node_id
 * @property string $table_name
 * @property int $data_id
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 * @property FileNode|null $node
 */
class FileDataLink extends ModelBase
{
    protected $casts = [
        'id' => 'integer',
        'workspace_id' => 'integer',
        'node_id' => 'integer',
        'data_id' => 'integer',
        'created_user_id' => 'integer',
    ];

    /**
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo<FileNode, $this>
     */
    public function node()
    {
        return $this->belongsTo(FileNode::class, 'node_id');
    }
}
