<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

use Exceedone\Exment\Model\File;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Reader\Xlsx;
use Smalot\PdfParser\Parser;

/**
 * Extract searchable text from one attachment without mutating it or its DB row.
 * Queue/cache/index integration is intentionally left to the next phase.
 */
final class AttachmentTextExtractor
{
    public function __construct(
        private readonly AttachmentTextConfig $config = new AttachmentTextConfig(),
        private readonly OoxmlTextExtractor $ooxml = new OoxmlTextExtractor(),
    ) {
    }

    public static function fromApplicationConfig(): self
    {
        return new self(AttachmentTextConfig::fromConfig());
    }

    public function extract(File $file): ExtractionResult
    {
        $disk = Storage::disk(config('admin.upload.disk'));
        $filename = (string) ($file->filename ?: $file->local_filename);

        return $this->extractFromDisk($disk, (string) $file->path, $filename);
    }

    /**
     * Public for jobs and tests which already have a local temporary file.
     */
    public function extractPath(string $path, ?string $filename = null): ExtractionResult
    {
        $filename ??= basename($path);
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        if (!is_file($path) || !is_readable($path)) {
            return $this->result(ExtractionResult::MISSING_FILE, $extension, null, null, null, null, 'missing_file');
        }

        $size = filesize($path);
        if ($size === false) {
            return $this->result(ExtractionResult::FAILED, $extension, null, null, null, null, 'file_size_unavailable');
        }
        if ($size > $this->config->maxBytes) {
            return $this->result(ExtractionResult::TOO_LARGE, $extension, null, (int) $size, null, null, 'file_size_limit');
        }

        $mime = $this->mimeType($path);
        $sha256 = hash_file('sha256', $path) ?: null;
        $format = $this->detectFormat($path, $extension);
        if ($format === null) {
            $known = in_array($extension, ['pdf', 'docx', 'pptx', 'xlsx'], true);
            $error = $this->hasOleSignature($path) && $known ? 'office_package_unreadable' : ($known ? 'invalid_file_signature' : 'unsupported_extension');

            return $this->result(
                $known ? ExtractionResult::FAILED : ExtractionResult::UNSUPPORTED,
                $extension,
                $mime,
                (int) $size,
                $sha256,
                null,
                $error,
            );
        }

        try {
            $output = match ($format) {
                'pdf' => $this->extractPdf($path),
                'docx', 'pptx' => $this->ooxml->extract($path, $format, $this->config),
                'xlsx' => $this->extractXlsx($path),
            };
            $normalized = TextNormalizer::normalize($output['text'], $this->config->maxCharacters);
            $status = $normalized['text'] === '' ? ExtractionResult::NO_EXTRACTABLE_TEXT : ExtractionResult::READY;

            return new ExtractionResult(
                $status,
                $normalized['text'] === '' ? null : $normalized['text'],
                $output['parser'],
                $extension,
                $mime,
                (int) $size,
                $sha256,
                $normalized['char_count'],
                $normalized['truncated'],
                null,
                $output['metadata'],
            );
        } catch (\LengthException $exception) {
            return $this->result(ExtractionResult::FAILED, $extension, $mime, (int) $size, $sha256, null, $exception->getMessage());
        } catch (\RuntimeException $exception) {
            $knownOfficeError = in_array($exception->getMessage(), [
                'office_package_invalid',
                'office_package_unreadable',
                'office_xml_unreadable',
            ], true);
            if ($knownOfficeError) {
                return $this->result(ExtractionResult::FAILED, $extension, $mime, (int) $size, $sha256, null, $exception->getMessage());
            }

            return $this->result(
                ExtractionResult::FAILED,
                $extension,
                $mime,
                (int) $size,
                $sha256,
                null,
                $format === 'pdf' ? 'pdf_unreadable' : ($format === 'xlsx' ? 'xlsx_unreadable' : 'office_package_unreadable'),
            );
        } catch (\Throwable $exception) {
            return $this->result(
                ExtractionResult::FAILED,
                $extension,
                $mime,
                (int) $size,
                $sha256,
                null,
                match ($format) {
                    'pdf' => 'pdf_unreadable',
                    'xlsx' => 'xlsx_unreadable',
                    default => 'office_package_unreadable',
                },
            );
        }
    }

    /**
     * Stages a remote-disk file without loading it wholly into worker memory.
     * The byte limit is checked while copying as well as by the final local size.
     *
     * @param mixed $disk Laravel filesystem adapter
     */
    public function extractFromDisk($disk, string $path, string $filename): ExtractionResult
    {
        $extension = strtolower((string) pathinfo($filename, PATHINFO_EXTENSION));
        try {
            if (!$disk->exists($path)) {
                return $this->result(ExtractionResult::MISSING_FILE, $extension, null, null, null, null, 'missing_file');
            }
            $input = $disk->readStream($path);
            if (!is_resource($input)) {
                return $this->result(ExtractionResult::FAILED, $extension, null, null, null, null, 'storage_read_failed');
            }
        } catch (\Throwable $exception) {
            return $this->result(ExtractionResult::FAILED, $extension, null, null, null, null, 'storage_read_failed');
        }

        $temporary = tempnam(sys_get_temp_dir(), 'exment-attachment-');
        if ($temporary === false) {
            fclose($input);
            return $this->result(ExtractionResult::FAILED, $extension, null, null, null, null, 'temporary_file_unavailable');
        }

        try {
            $output = fopen($temporary, 'wb');
            if ($output === false) {
                return $this->result(ExtractionResult::FAILED, $extension, null, null, null, null, 'temporary_file_unavailable');
            }
            $copy = self::copyStream($input, $output, $this->config->maxBytes);
            if ($copy['status'] === 'file_size_limit') {
                return $this->result(ExtractionResult::TOO_LARGE, $extension, null, $copy['written'], null, null, 'file_size_limit');
            }
            if ($copy['status'] !== 'ok') {
                return $this->result(ExtractionResult::FAILED, $extension, null, null, null, null, $copy['status']);
            }
            fclose($output);

            return $this->extractPath($temporary, $filename);
        } finally {
            if (isset($output) && is_resource($output)) {
                fclose($output);
            }
            fclose($input);
            @unlink($temporary);
        }
    }

    /**
     * Copy a stored file into a local temporary file, stopping as soon as the
     * byte limit is passed so an unbounded remote object is never read whole.
     *
     * A short write is reported rather than ignored: a truncated copy parses as
     * a complete document and would be cached as READY with partial text.
     *
     * @param resource $input
     * @param resource $output
     * @return array{status:string,written:int} status: ok|storage_read_failed|file_size_limit|write_failed
     */
    public static function copyStream($input, $output, int $maxBytes): array
    {
        $written = 0;
        while (!feof($input)) {
            $chunk = fread($input, 8192);
            if ($chunk === false) {
                return ['status' => 'storage_read_failed', 'written' => $written];
            }
            if ($chunk === '') {
                continue;
            }
            $written += strlen($chunk);
            if ($written > $maxBytes) {
                return ['status' => 'file_size_limit', 'written' => $written];
            }
            $put = @fwrite($output, $chunk);
            if ($put === false || $put < strlen($chunk)) {
                return ['status' => 'write_failed', 'written' => $written];
            }
        }

        return ['status' => 'ok', 'written' => $written];
    }

    /** @return array{text:string,parser:string,metadata:array<string,int>} */
    private function extractPdf(string $path): array
    {
        $document = (new Parser())->parseFile($path);

        return [
            'text' => $document->getText(),
            'parser' => 'smalot/pdfparser',
            'metadata' => ['page_count' => count($document->getPages())],
        ];
    }

    /** @return array{text:string,parser:string,metadata:array<string,int>} */
    private function extractXlsx(string $path): array
    {
        // PhpSpreadsheet expands the archive during load(), so enforce every
        // OOXML ZIP limit before it has a chance to allocate workbook XML.
        OoxmlPackageValidator::entries($path, $this->config);

        // load() builds the whole workbook object graph and a memory fatal is
        // not catchable, so judge the xl/ parts before it allocates anything.
        $workbookBytes = OoxmlPackageValidator::uncompressedBytes($path, 'xl/');
        if ($workbookBytes > $this->config->maxSpreadsheetBytes) {
            throw new \LengthException('xlsx_workbook_too_large');
        }

        $reader = new Xlsx();
        // Do not call setReadDataOnly(true): date, currency and percentage
        // formatting are part of the text users see and search for.
        $reader->setReadDataOnly(false);
        $reader->setReadEmptyCells(false);
        $reader->setIncludeCharts(false);
        $reader->setAllowExternalImages(false);
        $spreadsheet = $reader->load($path);

        try {
            $chunks = [];
            $cellCount = 0;
            $sheetCount = 0;
            foreach ($spreadsheet->getWorksheetIterator() as $worksheet) {
                $sheetCount++;
                $chunks[] = '[Sheet: ' . $worksheet->getTitle() . ']';
                foreach ($worksheet->getCoordinates(true) as $coordinate) {
                    $cellCount++;
                    if ($cellCount > $this->config->maxSpreadsheetCells) {
                        throw new \LengthException('xlsx_cell_limit');
                    }
                    $value = $this->cellText($worksheet->getCell($coordinate));
                    if ($value !== '') {
                        $chunks[] = $value;
                    }
                }
            }

            return [
                'text' => implode("\n", $chunks),
                'parser' => 'phpoffice/phpspreadsheet',
                'metadata' => ['sheet_count' => $sheetCount, 'cell_count' => $cellCount],
            ];
        } finally {
            $spreadsheet->disconnectWorksheets();
        }
    }

    private function cellText(Cell $cell): string
    {
        $raw = $cell->getValue();
        if ($raw === null || $raw === '') {
            return '';
        }
        if ($raw instanceof RichText) {
            return $raw->getPlainText();
        }

        $isFormula = is_string($raw) && str_starts_with($raw, '=');
        try {
            $calculated = $cell->getCalculatedValue();
            $formatted = $cell->getFormattedValue();
        } catch (\Throwable $exception) {
            // A formula without a usable cached result should not index the
            // formula expression itself.
            return $isFormula ? '' : $this->scalarText($raw);
        }

        $formattedText = $this->scalarText($formatted);
        $calculatedText = $this->scalarText($calculated);
        if ($formattedText === '') {
            return $calculatedText;
        }
        if ($calculatedText === '' || $calculatedText === $formattedText) {
            return $formattedText;
        }

        return $formattedText . ' ' . $calculatedText;
    }

    private function scalarText(mixed $value): string
    {
        if ($value instanceof RichText) {
            return $value->getPlainText();
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string) $value : '';
    }

    private function detectFormat(string $path, string $extension): ?string
    {
        if ($extension === 'pdf') {
            $signature = file_get_contents($path, false, null, 0, 5);
            return $signature === '%PDF-' ? 'pdf' : null;
        }
        if (!in_array($extension, ['docx', 'pptx', 'xlsx'], true) || !$this->hasZipSignature($path)) {
            return null;
        }

        $zip = new \ZipArchive();
        if ($zip->open($path) !== true) {
            return null;
        }
        try {
            return match ($extension) {
                'docx' => $zip->locateName('word/document.xml') !== false ? 'docx' : null,
                'pptx' => $zip->locateName('ppt/presentation.xml') !== false ? 'pptx' : null,
                'xlsx' => $zip->locateName('xl/workbook.xml') !== false ? 'xlsx' : null,
            };
        } finally {
            $zip->close();
        }
    }

    private function hasZipSignature(string $path): bool
    {
        $signature = file_get_contents($path, false, null, 0, 4);

        return in_array($signature, ["PK\x03\x04", "PK\x05\x06", "PK\x07\x08"], true);
    }

    private function hasOleSignature(string $path): bool
    {
        return file_get_contents($path, false, null, 0, 8) === "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";
    }

    private function mimeType(string $path): ?string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? $mime : null;
    }

    private function result(
        string $status,
        ?string $extension,
        ?string $mime,
        ?int $size,
        ?string $sha256,
        ?string $parser,
        ?string $error,
    ): ExtractionResult {
        return new ExtractionResult($status, null, $parser ?? 'none', $extension, $mime, $size, $sha256, 0, false, $error);
    }
}
