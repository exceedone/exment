<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;
use Illuminate\Support\Facades\DB;

/**
 * File server: the text files made and written in the browser (ISS00434,
 * phase 1): a text, a Markdown or a CSV file.
 *
 * - "New" makes an empty file in a folder, for those who may edit there (who
 *   may only upload could not write it), never over a file with the same name.
 *   In a folder that needs an approval, the request is sent at the first save
 *   with something in it, not for the empty file.
 * - A save is a new version through Library::addVersion(): the permission to
 *   edit, the check out, the folders that need an approval, the space of the
 *   workspace and the number of versions work as for an upload.
 * - A save from a version that is not the current one any more is refused
 *   (checked with the file locked), so what someone saved in between is not
 *   lost. A save of the same text adds no version.
 * - The line breaks and the BOM of the file are kept: the browser gives the
 *   text with LF, the file gets its own line breaks again.
 * - Only these kinds, in UTF-8, up to TEXT_EDIT_BYTES: another file is only
 *   downloaded and uploaded again as before.
 */
class TextFiles
{
    /** The kinds of a new file, and the files that are edited in the browser. */
    public const TYPES = ['txt', 'md', 'csv'];

    /**
     * The Office kinds of a new file: an empty Word, Excel or PowerPoint file
     * from a blank under resources/file_server/. Not edited here as text - the
     * file page previews it, and opens it in an Office editor when one is
     * there (Preview::registerOpener), or it is downloaded.
     */
    public const OFFICE_TYPES = ['docx', 'xlsx', 'pptx'];

    /** Every kind "New" offers, in the order of the menu. */
    public const NEW_TYPES = ['txt', 'md', 'csv', 'docx', 'xlsx', 'pptx'];

    public static function isOffice(string $type): bool
    {
        return in_array($type, static::OFFICE_TYPES, true);
    }

    /** The largest text written in the browser (the same as the preview of a text). */
    public const TEXT_EDIT_BYTES = Preview::TEXT_PREVIEW_BYTES;

    /** The largest Markdown text shown in the preview. */
    public const PREVIEW_BYTES = 262144;

    protected const BOM = "\xEF\xBB\xBF";

    protected Library $library;

    public function __construct(Library $library)
    {
        $this->library = $library;
    }

    public static function isEditable(FileNode $node): bool
    {
        return $node->isFile() && in_array($node->extension(), static::TYPES, true);
    }

    /**
     * The name the file gets: the name given, with the extension of the kind
     * when it has not got it ("memo" for Markdown is "memo.md").
     */
    public function nameOf(string $name, string $type): string
    {
        if (!in_array($type, static::NEW_TYPES, true)) {
            throw new FileServerException(exmtrans('file_server.error_text_type'));
        }
        $name = $this->library->newName($name);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $type) {
            $name .= '.' . $type;
        }
        return $this->library->newName($name);
    }

    /**
     * Make an empty file of the kind in the folder. A CSV starts with the
     * BOM of UTF-8, so that Excel reads it as UTF-8.
     */
    public function create(FileWorkspace $workspace, ?FileNode $parent, string $name, string $type): FileNode
    {
        $name = $this->nameOf($name, $type);
        // a file the user could never write is not made
        $this->library->access()->authorize($workspace, $parent, Level::EDIT);
        return $this->withLocal(static::emptyContent($type), function (string $local) use ($workspace, $parent, $name) {
            return $this->library->storeNewFile($workspace, $parent, $name, $local, null, false);
        });
    }

    /**
     * What a new file of the kind starts with: nothing; the BOM for a CSV;
     * the blank document for an Office kind.
     */
    protected static function emptyContent(string $type): string
    {
        if (static::isOffice($type)) {
            $blank = @file_get_contents(exment_package_path('resources/file_server/blank.' . $type));
            if (!is_string($blank) || $blank === '') {
                throw new FileServerException(exmtrans('file_server.error_text_type'));
            }
            return $blank;
        }
        return $type === 'csv' ? static::BOM : '';
    }

    /**
     * The text of the current version to edit, without the BOM, and the line
     * break the file mostly uses.
     *
     * @return array{text: string, bom: bool, eol: string, version: int, hash: string}
     */
    public function read(FileWorkspace $workspace, FileNode $node): array
    {
        $this->assertEditable($node);
        $version = $this->library->version($workspace, $node);
        if (intval($version->size) > static::TEXT_EDIT_BYTES) {
            throw new FileServerException(exmtrans('file_server.error_text_too_large', ['size' => static::mb(static::TEXT_EDIT_BYTES)]), 413);
        }
        $bytes = Library::disk()->get($version->path);
        $bytes = is_string($bytes) ? $bytes : '';
        $bom = str_starts_with($bytes, static::BOM);
        $text = $bom ? substr($bytes, strlen(static::BOM)) : $bytes;
        static::assertText($text);
        return [
            'text' => $text,
            'bom' => $bom,
            'eol' => static::eolOf($text, $node->extension()),
            'version' => intval($version->id),
            'hash' => hash('sha256', $bytes),
        ];
    }

    /**
     * Save the text as the new version of the file, from the version the
     * editor opened. The BOM and the line breaks of the file are kept.
     *
     * @return array{node: FileNode, changed: bool}
     */
    public function save(FileWorkspace $workspace, FileNode $node, string $text, int $baseVersion, ?string $comment = null): array
    {
        $this->assertEditable($node);
        // the permission first: one who may not edit learns nothing of the versions
        $this->library->access()->authorize($workspace, $node, Level::EDIT);
        static::assertText($text);

        return DB::transaction(function () use ($workspace, $node, $text, $baseVersion, $comment) {
            // the file locked: two saves from the same version cannot both pass
            /** @var FileNode|null $locked */
            $locked = FileNode::query()->whereKey($node->id)->lockForUpdate()->first();
            if (!isset($locked) || $baseVersion !== intval($locked->current_version_id)) {
                throw new FileServerException(exmtrans('file_server.error_version_conflict'), 409);
            }
            $current = $this->read($workspace, $locked);
            $text = str_starts_with($text, static::BOM) ? substr($text, strlen(static::BOM)) : $text;
            $text = $current['eol'] === "\r\n" ? strval(preg_replace('/\r?\n/', "\r\n", $text)) : str_replace("\r\n", "\n", $text);
            $bytes = ($current['bom'] ? static::BOM : '') . $text;
            if (strlen($bytes) > static::TEXT_EDIT_BYTES) {
                throw new FileServerException(exmtrans('file_server.error_text_too_large', ['size' => static::mb(static::TEXT_EDIT_BYTES)]), 413);
            }
            if (hash('sha256', $bytes) === $current['hash']) {
                return ['node' => $locked, 'changed' => false];
            }
            // the first text of a new file in a folder that needs an approval: now the request is sent
            $version = $locked->current_version;
            $firstText = $locked->isDraft() && $current['text'] === '' && isset($version) && intval($version->version_no) === 1;
            $saved = $this->withLocal($bytes, function (string $local) use ($workspace, $locked, $comment) {
                return $this->library->addVersion($workspace, $locked, $local, $comment);
            });
            if ($firstText && $text !== '') {
                $this->library->requestApproval($workspace, $saved);
            }
            return ['node' => $saved, 'changed' => true];
        });
    }

    /**
     * The line break the text mostly uses; for a text with none, CRLF for a
     * CSV (as Excel writes it) and LF for the others.
     */
    public static function eolOf(string $text, string $extension): string
    {
        $crlf = substr_count($text, "\r\n");
        $lf = substr_count($text, "\n") - $crlf;
        if ($crlf === 0 && $lf === 0) {
            return $extension === 'csv' ? "\r\n" : "\n";
        }
        return $crlf > $lf ? "\r\n" : "\n";
    }

    /**
     * The HTML of a Markdown text for the preview: the HTML in the text is
     * shown as text, the links that run scripts are dropped, the pictures are
     * links (nothing is loaded from another site), and the result is cleaned
     * as the other HTML of Exment.
     */
    public static function markdownHtml(string $text): string
    {
        if (strlen($text) > static::PREVIEW_BYTES) {
            throw new FileServerException(exmtrans('file_server.error_preview_too_large', ['size' => round(static::PREVIEW_BYTES / 1024)]), 413);
        }
        $html = \Illuminate\Support\Str::markdown($text, [
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'max_nesting_level' => 50,
        ]);
        $html = strval(html_clean($html));
        return strval(preg_replace_callback('/<img\b[^>]*>/i', function ($m) {
            $src = preg_match('/\ssrc="([^"]*)"/i', $m[0], $s) === 1 ? html_entity_decode($s[1]) : '';
            $alt = preg_match('/\salt="([^"]*)"/i', $m[0], $a) === 1 ? html_entity_decode($a[1]) : '';
            $label = '[' . ($alt !== '' ? $alt : basename(strval(parse_url($src, PHP_URL_PATH)))) . ']';
            return preg_match('#^https?://#i', $src) === 1
                ? '<a href="' . e($src) . '">' . e($label) . '</a>'
                : e($label);
        }, $html));
    }

    /**
     * Text in UTF-8 only: another encoding would change when saved again, and
     * a file with NUL is not a text.
     */
    protected static function assertText(string $text): void
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new FileServerException(exmtrans('file_server.error_text_encoding'), 422);
        }
        if (str_contains($text, "\0")) {
            throw new FileServerException(exmtrans('file_server.error_text_binary'), 422);
        }
    }

    protected function assertEditable(FileNode $node): void
    {
        if (!static::isEditable($node)) {
            throw new FileServerException(exmtrans('file_server.error_text_type'));
        }
    }

    protected static function mb(int $bytes): string
    {
        return strval(round($bytes / 1048576, 1));
    }

    /**
     * Run the callback with a local temporary file of the bytes, removed after.
     *
     * @param \Closure(string): FileNode $callback
     */
    protected function withLocal(string $bytes, \Closure $callback): FileNode
    {
        $local = Library::tempPath('text_');
        file_put_contents($local, $bytes);
        try {
            return $callback($local);
        } finally {
            @unlink($local);
        }
    }
}
