<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNode;
use Exceedone\Exment\Model\FileWorkspace;

/**
 * File server: the text files made and written in the browser (ISS00434,
 * phase 1): a text, a Markdown or a CSV file.
 *
 * - "New" makes an empty file in a folder: the same permission as an upload,
 *   and never over a file with the same name.
 * - A save is a new version through Library::addVersion(): the permission to
 *   edit, the check out, the folders that need an approval, the space of the
 *   workspace and the number of versions work as for an upload.
 * - A save from a version that is not the current one any more is refused,
 *   so what someone saved in between is not lost.
 * - Only these kinds, in UTF-8, up to TEXT_EDIT_BYTES: another file is only
 *   downloaded and uploaded again as before.
 */
class TextFiles
{
    /** The kinds of a new file, and the files that are edited in the browser. */
    public const TYPES = ['txt', 'md', 'csv'];

    /** The largest text written in the browser (the same as the preview of a text). */
    public const TEXT_EDIT_BYTES = Preview::TEXT_PREVIEW_BYTES;

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
        if (!in_array($type, static::TYPES, true)) {
            throw new FileServerException(exmtrans('file_server.error_text_type'));
        }
        $name = $this->library->validName($name);
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== $type) {
            $name .= '.' . $type;
        }
        return $this->library->validName($name);
    }

    /**
     * Make an empty file of the kind in the folder. A CSV starts with the
     * BOM of UTF-8, so that Excel reads it as UTF-8.
     */
    public function create(FileWorkspace $workspace, ?FileNode $parent, string $name, string $type): FileNode
    {
        $name = $this->nameOf($name, $type);
        return $this->withLocal($type === 'csv' ? static::BOM : '', function (string $local) use ($workspace, $parent, $name) {
            return $this->library->storeNewFile($workspace, $parent, $name, $local);
        });
    }

    /**
     * The text of the current version to edit, without the BOM.
     *
     * @return array{text: string, bom: bool, version: int}
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
        if (!mb_check_encoding($text, 'UTF-8')) {
            // saved again as UTF-8 it would change for those who read it in its own encoding
            throw new FileServerException(exmtrans('file_server.error_text_encoding'), 422);
        }
        return ['text' => $text, 'bom' => $bom, 'version' => intval($version->id)];
    }

    /**
     * Save the text as the new version of the file. The BOM the file had is kept.
     */
    public function save(FileWorkspace $workspace, FileNode $node, string $text, ?int $baseVersion, ?string $comment = null): FileNode
    {
        $this->assertEditable($node);
        if (!mb_check_encoding($text, 'UTF-8')) {
            throw new FileServerException(exmtrans('file_server.error_text_encoding'), 422);
        }
        if (strlen($text) > static::TEXT_EDIT_BYTES) {
            throw new FileServerException(exmtrans('file_server.error_text_too_large', ['size' => static::mb(static::TEXT_EDIT_BYTES)]), 413);
        }
        if (isset($baseVersion) && $baseVersion !== intval($node->current_version_id)) {
            throw new FileServerException(exmtrans('file_server.error_version_conflict'), 409);
        }
        $current = $this->read($workspace, $node);
        $text = str_starts_with($text, static::BOM) ? substr($text, strlen(static::BOM)) : $text;
        return $this->withLocal(($current['bom'] ? static::BOM : '') . $text, function (string $local) use ($workspace, $node, $comment) {
            return $this->library->addVersion($workspace, $node, $local, $comment);
        });
    }

    /**
     * The HTML of a Markdown text for the preview: the HTML in the text is
     * shown as text, the links that run scripts are dropped, and the result
     * is cleaned as the other HTML of Exment.
     */
    public static function markdownHtml(string $text): string
    {
        if (strlen($text) > static::TEXT_EDIT_BYTES) {
            throw new FileServerException(exmtrans('file_server.error_text_too_large', ['size' => static::mb(static::TEXT_EDIT_BYTES)]), 413);
        }
        $html = \Illuminate\Support\Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
        return strval(html_clean($html));
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
