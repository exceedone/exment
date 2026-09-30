<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

/**
 * Resource limits for attachment extraction. They are deliberately independent
 * from the upload limit: an accepted attachment is not automatically safe to
 * parse in a queue worker.
 */
final class AttachmentTextConfig
{
    public function __construct(
        public readonly int $maxBytes = 26214400,
        public readonly int $maxCharacters = 500000,
        public readonly int $maxZipEntries = 5000,
        public readonly int $maxZipUncompressedBytes = 104857600,
        public readonly int $maxZipCompressionRatio = 100,
        public readonly int $maxSpreadsheetCells = 200000,
    ) {
    }

    public static function fromConfig(): self
    {
        $values = (array) config('meilisearch.attachment_extraction', []);

        return new self(
            max(1, (int) ($values['max_bytes'] ?? 26214400)),
            max(1, (int) ($values['max_characters'] ?? 500000)),
            max(1, (int) ($values['max_zip_entries'] ?? 5000)),
            max(1, (int) ($values['max_zip_uncompressed_bytes'] ?? 104857600)),
            max(1, (int) ($values['max_zip_compression_ratio'] ?? 100)),
            max(1, (int) ($values['max_spreadsheet_cells'] ?? 200000)),
        );
    }
}
