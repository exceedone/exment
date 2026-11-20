<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Jobs\FileServerRenditionJob;
use Exceedone\Exment\Model\FileNodeVersion;

/**
 * File server: how a file is shown in the browser, and the files made for it
 * (the thumbnail, the PDF of an Office file) (ISS00293, phase 2).
 *
 * - image, video, audio, PDF, text: shown as they are
 * - Office (Word, Excel, PowerPoint...): converted to PDF by LibreOffice when
 *   the site has it (config exment.file_server_soffice_path); otherwise download only
 * - the thumbnail of an image is made with GD; of a PDF with Imagick when it is there
 *
 * An editor (as the Office of the Groupware, ISS00233) can be added as an
 * "opener" (see openers()), so that a file opens in it from the file page.
 */
class Preview
{
    public const KIND_IMAGE = 'image';
    public const KIND_VIDEO = 'video';
    public const KIND_AUDIO = 'audio';
    public const KIND_PDF = 'pdf';
    public const KIND_TEXT = 'text';
    public const KIND_OFFICE = 'office';
    public const KIND_NONE = 'none';

    public const THUMBNAIL_SIZE = 320;
    public const TEXT_PREVIEW_BYTES = 1048576;

    /** @var array<string, string> */
    protected const OFFICE_TYPES = [
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'odt' => 'application/vnd.oasis.opendocument.text',
        'ods' => 'application/vnd.oasis.opendocument.spreadsheet',
        'odp' => 'application/vnd.oasis.opendocument.presentation',
        'rtf' => 'application/rtf',
    ];

    /** @var string[] the types shown in the page as they are (never html or svg: they can run scripts) */
    protected const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/bmp',
        'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/x-wav', 'audio/webm', 'audio/mp4', 'audio/aac',
        'application/pdf',
        'text/plain', 'text/csv',
    ];

    /** @var string[] */
    protected const TEXT_EXTENSIONS = ['txt', 'csv', 'tsv', 'log', 'md', 'json', 'xml', 'yml', 'yaml', 'ini', 'conf', 'sql', 'php', 'js', 'ts', 'css', 'py', 'java', 'c', 'h', 'cs', 'sh', 'bat', 'ps1', 'html', 'htm', 'svg'];

    /** @var array<string, callable(\Exceedone\Exment\Model\FileNode): mixed> */
    protected static array $openers = [];

    public static function guessMimeType(string $localPath, string $name): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (isset(static::OFFICE_TYPES[$ext])) {
            return static::OFFICE_TYPES[$ext];
        }
        $mime = null;
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $mime = finfo_file($finfo, $localPath) ?: null;
                finfo_close($finfo);
            }
        }
        if (!isset($mime) || $mime === 'application/octet-stream' || $mime === 'text/plain') {
            $byExt = \Symfony\Component\Mime\MimeTypes::getDefault()->getMimeTypes($ext);
            if (count($byExt) > 0 && ($mime === null || $mime === 'application/octet-stream' || in_array($ext, static::TEXT_EXTENSIONS, true))) {
                $mime = $byExt[0];
            }
        }
        return $mime ?? 'application/octet-stream';
    }

    public static function kind(?string $mime, string $name): string
    {
        $mime = strtolower(strval($mime));
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (isset(static::OFFICE_TYPES[$ext])) {
            return static::KIND_OFFICE;
        }
        if ($mime === 'application/pdf') {
            return static::KIND_PDF;
        }
        if (str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml') {
            return static::KIND_IMAGE;
        }
        if (str_starts_with($mime, 'video/')) {
            return static::KIND_VIDEO;
        }
        if (str_starts_with($mime, 'audio/')) {
            return static::KIND_AUDIO;
        }
        if (str_starts_with($mime, 'text/') || in_array($ext, static::TEXT_EXTENSIONS, true)) {
            return static::KIND_TEXT;
        }
        return static::KIND_NONE;
    }

    /**
     * Whether the bytes may be sent to be shown in the page as they are.
     */
    public static function isInlineSafe(?string $mime): bool
    {
        return in_array(strtolower(strval($mime)), static::INLINE_TYPES, true);
    }

    public static function sofficePath(): ?string
    {
        $path = config('exment.file_server_soffice_path');
        return is_string($path) && $path !== '' ? $path : null;
    }

    /**
     * Make the thumbnail and the PDF of a version later (on the queue).
     */
    public static function queueRenditions(FileNodeVersion $version): void
    {
        $node = $version->node;
        $kind = static::kind($version->mime_type, isset($node) ? $node->name : '');
        $needs = $kind === static::KIND_IMAGE
            || ($kind === static::KIND_PDF && extension_loaded('imagick'))
            || ($kind === static::KIND_OFFICE && static::sofficePath() !== null);
        if (!$needs) {
            $version->rendition_status = FileNodeVersion::RENDITION_NONE;
            $version->save();
            return;
        }
        try {
            FileServerRenditionJob::dispatch($version->id);
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not queue the preview of version ' . $version->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Make the thumbnail and the PDF of a version now.
     */
    public static function makeRenditions(FileNodeVersion $version): void
    {
        $node = $version->node;
        if (!isset($node)) {
            return;
        }
        $kind = static::kind($version->mime_type, $node->name);
        $library = new Library(new Access(null, true, []));
        $local = null;
        $status = FileNodeVersion::RENDITION_NONE;
        try {
            $local = $library->downloadToLocal($version);
            $dir = dirname($version->path);

            if ($kind === static::KIND_OFFICE && static::sofficePath() !== null) {
                $pdf = static::convertToPdf($local, $node->extension());
                if (isset($pdf)) {
                    $path = $dir . '/preview_' . $version->version_no . '.pdf';
                    static::putLocal($path, $pdf);
                    $version->preview_path = $path;
                    $status = FileNodeVersion::RENDITION_DONE;
                    $thumb = static::pdfThumbnail($pdf);
                    @unlink($pdf);
                    if (isset($thumb)) {
                        $version->thumbnail_path = static::storeThumbnail($dir, $version, $thumb);
                    }
                } else {
                    $status = FileNodeVersion::RENDITION_FAILED;
                }
            } elseif ($kind === static::KIND_IMAGE) {
                $thumb = static::imageThumbnail($local);
                if (isset($thumb)) {
                    $version->thumbnail_path = static::storeThumbnail($dir, $version, $thumb);
                    $status = FileNodeVersion::RENDITION_DONE;
                }
            } elseif ($kind === static::KIND_PDF) {
                $thumb = static::pdfThumbnail($local);
                if (isset($thumb)) {
                    $version->thumbnail_path = static::storeThumbnail($dir, $version, $thumb);
                    $status = FileNodeVersion::RENDITION_DONE;
                }
            }
        } catch (\Throwable $e) {
            \Log::warning('[FileServer] could not make the preview of version ' . $version->id . ': ' . $e->getMessage());
            $status = FileNodeVersion::RENDITION_FAILED;
        } finally {
            if (isset($local)) {
                @unlink($local);
            }
        }
        $version->rendition_status = $status;
        $version->save();
    }

    protected static function storeThumbnail(string $dir, FileNodeVersion $version, string $localJpeg): string
    {
        $isPng = substr(strval(file_get_contents($localJpeg, false, null, 0, 8)), 0, 8) === "PNG

";
        $path = $dir . '/thumb_' . $version->version_no . ($isPng ? '.png' : '.jpg');
        static::putLocal($path, $localJpeg);
        @unlink($localJpeg);
        return $path;
    }

    protected static function putLocal(string $path, string $local): void
    {
        $stream = fopen($local, 'rb');
        if ($stream === false) {
            return;
        }
        try {
            Library::disk()->writeStream($path, $stream);
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * A thumbnail (JPEG, or PNG when GD cannot write JPEG) of an image (a local temporary file), with GD.
     */
    public static function imageThumbnail(string $local): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        $bytes = file_get_contents($local);
        if ($bytes === false) {
            return null;
        }
        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1, static::THUMBNAIL_SIZE / max(1, $w, $h));
        $tw = max(1, intval(round($w * $scale)));
        $th = max(1, intval(round($h * $scale)));
        $dst = imagecreatetruecolor($tw, $th);
        $white = imagecolorallocate($dst, 255, 255, 255);
        imagefill($dst, 0, 0, $white === false ? 0 : $white);
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $tw, $th, $w, $h);
        // JPEG when GD has it (smaller), otherwise PNG
        $out = Library::tempPath('thumb_');
        if (function_exists('imagejpeg')) {
            imagejpeg($dst, $out, 82);
        } else {
            imagepng($dst, $out, 6);
        }
        imagedestroy($src);
        imagedestroy($dst);
        return $out;
    }

    /**
     * A JPEG thumbnail of the first page of a PDF, with Imagick when it is there.
     */
    public static function pdfThumbnail(string $local): ?string
    {
        if (!extension_loaded('imagick')) {
            return null;
        }
        try {
            $class = 'Imagick';
            $im = new $class();
            $im->setResolution(72, 72);
            $im->readImage($local . '[0]');
            $im->setImageFormat('jpeg');
            $im->thumbnailImage(static::THUMBNAIL_SIZE, static::THUMBNAIL_SIZE, true);
            $out = Library::tempPath('thumb_');
            $im->writeImage($out);
            $im->clear();
            return $out;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Convert an Office file to PDF with LibreOffice (headless). Returns the
     * local path of the PDF, or null.
     */
    public static function convertToPdf(string $local, string $ext): ?string
    {
        $soffice = static::sofficePath();
        if (!isset($soffice)) {
            return null;
        }
        $work = Library::tempPath('conv_');
        @mkdir($work, 0777, true);
        $input = $work . DIRECTORY_SEPARATOR . 'input.' . preg_replace('/[^a-z0-9]/', '', $ext);
        copy($local, $input);
        $cmd = escapeshellarg($soffice) . ' --headless --norestore --convert-to pdf --outdir ' . escapeshellarg($work) . ' ' . escapeshellarg($input)
            . ' -env:UserInstallation=file://' . str_replace('\\', '/', $work) . '/profile';
        $timeout = intval(config('exment.file_server_soffice_timeout', 120));
        $process = \Symfony\Component\Process\Process::fromShellCommandline($cmd, $work, ['HOME' => $work]);
        $process->setTimeout($timeout);
        $process->run();
        $pdf = $work . DIRECTORY_SEPARATOR . 'input.pdf';
        $result = null;
        if (is_file($pdf)) {
            $result = Library::tempPath('pdf_');
            rename($pdf, $result);
        }
        \File::deleteDirectory($work);
        return $result;
    }

    // ---------------------------------------------------------------- openers

    /**
     * Add a way to open a file in another screen (an editor). The callback gets
     * the file and returns the link, or null when it cannot open that file.
     *
     * @param callable(\Exceedone\Exment\Model\FileNode): mixed $callback returns array{label: string, url: string, icon?: string} or null
     */
    public static function registerOpener(string $key, callable $callback): void
    {
        static::$openers[$key] = $callback;
    }

    /**
     * @return array<int, array{label: string, url: string, icon?: string}>
     */
    public static function openers(\Exceedone\Exment\Model\FileNode $node): array
    {
        $list = [];
        foreach (static::$openers as $callback) {
            try {
                $opener = $callback($node);
            } catch (\Throwable $e) {
                $opener = null;
            }
            if (is_array($opener) && is_string($opener['label'] ?? null) && is_string($opener['url'] ?? null)) {
                $list[] = ['label' => $opener['label'], 'url' => $opener['url'], 'icon' => is_string($opener['icon'] ?? null) ? $opener['icon'] : 'fa-external-link'];
            }
        }
        return $list;
    }
}
