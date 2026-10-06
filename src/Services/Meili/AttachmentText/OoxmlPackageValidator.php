<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

/**
 * Validates the ZIP container shared by DOCX, PPTX and XLSX before a parser
 * reads an XML part into memory. The outer file-size limit alone is not enough:
 * an OOXML file is a ZIP archive and can be small while expanding enormously.
 */
final class OoxmlPackageValidator
{
    /** @return string[] archive entry names */
    public static function entries(string $path, AttachmentTextConfig $config): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('office_package_unreadable');
        }

        try {
            if ($zip->numFiles > $config->maxZipEntries) {
                throw new \LengthException('office_package_entry_limit');
            }

            $totalUncompressed = 0;
            $entries = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'] ?? null;
                if (!is_string($name) || !self::isSafeEntryName($name)) {
                    throw new \RuntimeException('office_package_invalid');
                }

                $size = (int) ($stat['size'] ?? 0);
                $compressed = (int) ($stat['comp_size'] ?? 0);
                $totalUncompressed += $size;
                if ($totalUncompressed > $config->maxZipUncompressedBytes) {
                    throw new \LengthException('office_package_uncompressed_limit');
                }
                // The ratio only means something for an entry large enough to
                // matter: ordinary XML with repeated rows compresses far past
                // 100:1, and the absolute uncompressed cap already bounds a bomb.
                $ratioApplies = $size > $config->minZipRatioBytes;
                if ($size > 0 && ($compressed === 0
                    || ($ratioApplies && $size / $compressed > $config->maxZipCompressionRatio))) {
                    throw new \LengthException('office_package_compression_ratio');
                }
                $entries[] = $name;
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * Uncompressed bytes of the entries under one prefix. Reads only ZIP
     * metadata, so it answers before any part is expanded into memory.
     */
    public static function uncompressedBytes(string $path, string $prefix = ''): int
    {
        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            throw new \RuntimeException('office_package_unreadable');
        }

        try {
            $total = 0;
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                $name = $stat['name'] ?? '';
                if ($prefix === '' || (is_string($name) && str_starts_with($name, $prefix))) {
                    $total += (int) ($stat['size'] ?? 0);
                }
            }

            return $total;
        } finally {
            $zip->close();
        }
    }

    private static function isSafeEntryName(string $name): bool
    {
        return $name !== ''
            && !str_contains($name, "\0")
            && !str_starts_with($name, '/')
            && !preg_match('#(^|[\\/])\.\.([\\/]|$)#', $name)
            && !preg_match('#^[A-Za-z]:[\\/]#', $name);
    }
}
