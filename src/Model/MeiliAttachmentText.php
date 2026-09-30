<?php

namespace Exceedone\Exment\Model;

/**
 * Cached, normalized content extracted from one File row.
 *
 * The cache is deliberately not a search index: permission enforcement stays
 * at the CustomValue document level in the existing Meilisearch pipeline.
 */
class MeiliAttachmentText extends ModelBase
{
    protected $table = 'meili_attachment_texts';

    protected $fillable = [
        'file_uuid',
        'file_hash',
        'extractor_version',
        'status',
        'parser',
        'extension',
        'mime',
        'size_bytes',
        'text',
        'text_length',
        'truncated',
        'error_code',
        'metadata',
        'extracted_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'text_length' => 'integer',
        'truncated' => 'boolean',
        'metadata' => 'array',
        'extracted_at' => 'datetime',
    ];
}
