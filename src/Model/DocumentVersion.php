<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * An older version of an attachment of a record (see Services\DocumentVersionService).
 *
 * @property int $id
 * @property int $document_id
 * @property int $version_no
 * @property string $file_uuid
 * @property string|null $document_name
 * @property int|null $created_user_id
 * @property \Carbon\Carbon|null $created_at
 */
class DocumentVersion extends Model
{
    protected $table = 'document_versions';

    protected $fillable = ['document_id', 'version_no', 'file_uuid', 'document_name', 'created_user_id'];

    protected $casts = [
        'document_id' => 'integer',
        'version_no' => 'integer',
        'created_user_id' => 'integer',
    ];
}
