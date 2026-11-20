<?php

namespace Exceedone\Exment\Services\FileServer;

/**
 * File server, phase 4 (ISS00378): the text in a file, for the search.
 *
 * - text (txt, csv, md, html, json, xml...): the file itself (Shift_JIS and
 *   EUC-JP are read too)
 * - Office (docx, xlsx, pptx) and OpenDocument (odt, ods, odp): the XML in
 *   the zip, with nothing more installed
 * - PDF: pdftotext (poppler-utils), when it is installed
 *   (exment.file_server_pdftotext_path, or found in /usr/bin)
 * - images, and PDF without text (a scan): OCR by tesseract, when it is set
 *   (exment.file_server_ocr_path; the languages: exment.file_server_ocr_lang)
 *
 * The text is cut at exment.file_server_text_max_chars.
 */
class TextExtractor
{
    public const METHOD_TEXT = 'text';
    public const METHOD_OFFICE = 'office';
    public const METHOD_PDF = 'pdf';
    public const METHOD_OCR = 'ocr';

    protected const TEXT_EXTENSIONS = ['txt', 'csv', 'tsv', 'md', 'markdown', 'log', 'json', 'xml', 'html', 'htm', 'yml', 'yaml', 'ini', 'sql', 'rtf'];
    protected const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'bmp', 'tif', 'tiff', 'webp'];

    public static function maxChars(): int
    {
        return max(1000, intval(config('exment.file_server_text_max_chars', 1000000)));
    }

    public static function pdftotextPath(): ?string
    {
        return static::command(config('exment.file_server_pdftotext_path'), ['/usr/bin/pdftotext', '/usr/local/bin/pdftotext']);
    }

    public static function ocrPath(): ?string
    {
        // OCR is slow: only when the site sets it
        return static::command(config('exment.file_server_ocr_path'), []);
    }

    /**
     * @param string[] $candidates
     */
    protected static function command(mixed $configured, array $candidates): ?string
    {
        if (is_string($configured) && $configured !== '') {
            return is_file($configured) && is_executable($configured) ? $configured : null;
        }
        foreach ($candidates as $candidate) {
            if (@is_file($candidate) && @is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * How the text of a file can be taken (null: it cannot).
     */
    public static function method(string $name): ?string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (in_array($ext, static::TEXT_EXTENSIONS, true)) {
            return static::METHOD_TEXT;
        }
        if (in_array($ext, ['docx', 'docm', 'xlsx', 'xlsm', 'pptx', 'pptm', 'odt', 'ods', 'odp'], true)) {
            return static::METHOD_OFFICE;
        }
        if ($ext === 'pdf') {
            return static::pdftotextPath() !== null || static::ocrPath() !== null ? static::METHOD_PDF : null;
        }
        if (in_array($ext, static::IMAGE_EXTENSIONS, true)) {
            return static::ocrPath() !== null ? static::METHOD_OCR : null;
        }
        return null;
    }

    /**
     * The text of a local file: [text, method]. Null: no text can be taken.
     *
     * @return array{0: string, 1: string}|null
     */
    public static function extract(string $localPath, string $name): ?array
    {
        $method = static::method($name);
        if ($method === null) {
            return null;
        }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $text = null;
        switch ($method) {
            case static::METHOD_TEXT:
                $text = static::readText($localPath, $ext);
                break;
            case static::METHOD_OFFICE:
                $text = static::readOffice($localPath, $ext);
                break;
            case static::METHOD_PDF:
                $text = static::readPdf($localPath);
                if (static::isBlank($text) && static::ocrPath() !== null) {
                    $text = static::ocr($localPath, true);
                    $method = static::METHOD_OCR;
                }
                break;
            case static::METHOD_OCR:
                $text = static::ocr($localPath, false);
                break;
        }
        if ($text === null) {
            return null;
        }
        return [static::clean($text), $method];
    }

    public static function isBlank(?string $text): bool
    {
        return $text === null || trim(preg_replace('/[\s\x{3000}]+/u', '', $text) ?? '') === '';
    }

    /**
     * One space for each run of spaces, the control characters taken away, cut at the limit.
     */
    public static function clean(string $text): string
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $text) ?? $text;
        $text = preg_replace('/[ \t\x{3000}]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s*\n\s*/u', "\n", $text) ?? $text;
        return mb_substr(trim($text), 0, static::maxChars());
    }

    protected static function readText(string $localPath, string $ext): ?string
    {
        $bytes = @file_get_contents($localPath, false, null, 0, max(0, static::maxChars() * 4));
        if (!is_string($bytes)) {
            return null;
        }
        if (strncmp($bytes, "\xEF\xBB\xBF", 3) === 0) {
            $bytes = substr($bytes, 3);
        } elseif (!mb_check_encoding($bytes, 'UTF-8')) {
            $bytes = strval(mb_convert_encoding($bytes, 'UTF-8', 'SJIS-win,EUC-JP,UTF-8'));
        }
        if (in_array($ext, ['html', 'htm', 'xml', 'rtf'], true)) {
            if ($ext === 'rtf') {
                $bytes = preg_replace('/\\\\[a-z]+-?\d* ?|[{}]/i', ' ', $bytes) ?? $bytes;
            } else {
                $bytes = preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $bytes) ?? $bytes;
                $bytes = html_entity_decode(strip_tags(str_replace('<', ' <', $bytes)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }
        return $bytes;
    }

    /**
     * The text of an Office file: the XML parts that hold the words.
     */
    protected static function readOffice(string $localPath, string $ext): ?string
    {
        if (!class_exists(\ZipArchive::class)) {
            return null;
        }
        $zip = new \ZipArchive();
        if ($zip->open($localPath) !== true) {
            return null;
        }
        try {
            $patterns = match (true) {
                in_array($ext, ['docx', 'docm'], true) => ['#^word/(document|header\d*|footer\d*|footnotes|endnotes|comments)\.xml$#'],
                in_array($ext, ['xlsx', 'xlsm'], true) => ['#^xl/sharedStrings\.xml$#', '#^xl/worksheets/sheet\d+\.xml$#'],
                in_array($ext, ['pptx', 'pptm'], true) => ['#^ppt/(slides/slide\d+|notesSlides/notesSlide\d+)\.xml$#'],
                default => ['#^content\.xml$#'],
            };
            $names = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->getNameIndex($i);
                if (!is_string($entry)) {
                    continue;
                }
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $entry) === 1) {
                        $names[] = $entry;
                        break;
                    }
                }
            }
            natsort($names);
            $parts = [];
            $length = 0;
            foreach ($names as $entry) {
                $stat = $zip->statName($entry);
                // a part too large to read (a zip bomb)
                if ($stat === false || intval($stat['size']) > 200 * 1024 * 1024) {
                    continue;
                }
                $xml = $zip->getFromName($entry);
                if (!is_string($xml)) {
                    continue;
                }
                if (str_contains($entry, 'worksheets/')) {
                    // the cells with the words in them (the others are in sharedStrings)
                    if (preg_match_all('#<is>(.*?)</is>|<c [^>]*t="str"[^>]*>.*?<v>(.*?)</v>#s', $xml, $m) > 0) {
                        $xml = implode("\n", array_merge($m[1], $m[2]));
                    } else {
                        continue;
                    }
                }
                $parts[] = static::xmlText($xml);
                $length += mb_strlen(end($parts));
                if ($length > static::maxChars()) {
                    break;
                }
            }
            return implode("\n", $parts);
        } finally {
            $zip->close();
        }
    }

    /**
     * The words of an XML part: a line break at each paragraph, row and cell.
     */
    public static function xmlText(string $xml): string
    {
        $xml = preg_replace('#</(w:p|a:p|text:p|text:h|si|row|table:table-row)>#', "\n", $xml) ?? $xml;
        $xml = preg_replace('#<(w:tab|w:br|text:tab|text:line-break|text:s)\b[^>]*/?>|</(w:tc|table:table-cell|c)>#', ' ', $xml) ?? $xml;
        // the attributes are not words (w:instrText of the fields is left out too)
        $xml = preg_replace('#<w:instrText\b[^>]*>.*?</w:instrText>#s', '', $xml) ?? $xml;
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    protected static function readPdf(string $localPath): ?string
    {
        $command = static::pdftotextPath();
        if ($command === null) {
            return null;
        }
        $out = Library::tempPath('fs_text') . '.txt';
        try {
            $ok = static::run([$command, '-enc', 'UTF-8', '-q', $localPath, $out]);
            if (!$ok || !is_file($out)) {
                return null;
            }
            $text = @file_get_contents($out, false, null, 0, max(0, static::maxChars() * 4));
            return is_string($text) ? $text : null;
        } finally {
            @unlink($out);
        }
    }

    /**
     * OCR of an image (or of the first pages of a PDF, made into images by
     * pdftoppm when it is there).
     */
    protected static function ocr(string $localPath, bool $isPdf): ?string
    {
        $command = static::ocrPath();
        if ($command === null) {
            return null;
        }
        $lang = preg_replace('/[^a-zA-Z_+]/', '', strval(config('exment.file_server_ocr_lang', 'jpn+eng'))) ?: 'eng';
        $images = [$localPath];
        $made = [];
        if ($isPdf) {
            $ppm = dirname($command) . '/pdftoppm';
            $pdftotext = static::pdftotextPath();
            if (!is_executable($ppm) && $pdftotext !== null) {
                $ppm = dirname($pdftotext) . '/pdftoppm';
            }
            if (!is_executable($ppm)) {
                return null;
            }
            $prefix = Library::tempPath('fs_ocr');
            $pages = max(1, intval(config('exment.file_server_ocr_max_pages', 10)));
            static::run([$ppm, '-r', '200', '-gray', '-png', '-l', strval($pages), $localPath, $prefix]);
            $made = glob($prefix . '*.png') ?: [];
            natsort($made);
            $images = array_values($made);
        }
        try {
            $texts = [];
            foreach ($images as $image) {
                $base = Library::tempPath('fs_ocr_out');
                static::run([$command, $image, $base, '-l', $lang]);
                $text = @file_get_contents($base . '.txt');
                @unlink($base . '.txt');
                if (is_string($text)) {
                    $texts[] = $text;
                }
            }
            return count($texts) > 0 ? implode("\n", $texts) : null;
        } finally {
            foreach ($made as $file) {
                @unlink($file);
            }
        }
    }

    /**
     * Run a command (no shell), with the time limit of the site.
     *
     * @param string[] $command
     */
    protected static function run(array $command): bool
    {
        try {
            $process = new \Symfony\Component\Process\Process($command);
            $process->setTimeout(max(10, intval(config('exment.file_server_text_timeout', 120))));
            $process->run();
            return $process->isSuccessful();
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not take the text: ' . $e->getMessage());
            return false;
        }
    }
}
