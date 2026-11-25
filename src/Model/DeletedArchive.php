<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * The archive of a deleted row that was deleted for good by exment:trash-purge (ISS00147).
 * Written before the row is deleted, so that it can be restored later.
 *
 * @property int $id
 * @property int|null $custom_table_id
 * @property string $custom_table_name
 * @property int $value_id the id the row had
 * @property array<string, mixed>|null $value the value and the system columns of the row
 * @property array<int, mixed>|null $children the rows of the 1:n relations
 * @property array<int, mixed>|null $comments
 * @property array<int, mixed>|null $revisions
 * @property array<int, mixed>|null $files the rows of the files, with archive_disk and archive_path
 * @property \Carbon\Carbon|null $deleted_at when the row was deleted (to the trash)
 * @property \Carbon\Carbon|null $archived_at
 * @property int|null $archived_by null: the scheduler
 * @property \Carbon\Carbon|null $purge_after when the archive may be removed (null: kept)
 */
class DeletedArchive extends Model
{
    public const TABLE = 'deleted_archives';

    protected $table = self::TABLE;

    protected $fillable = [
        'custom_table_id', 'custom_table_name', 'value_id', 'value', 'children', 'comments', 'revisions', 'files',
        'deleted_at', 'archived_at', 'archived_by', 'purge_after',
    ];

    protected $casts = [
        'value' => 'array',
        'children' => 'array',
        'comments' => 'array',
        'revisions' => 'array',
        'files' => 'array',
        'deleted_at' => 'datetime',
        'archived_at' => 'datetime',
        'purge_after' => 'date',
        'custom_table_id' => 'integer',
        'value_id' => 'integer',
        'archived_by' => 'integer',
    ];
}
