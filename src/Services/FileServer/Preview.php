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
 *   the site has it: a converter of its own on the inner network (Gotenberg,
 *   config exment.file_server_soffice_url, ISS01704), or LibreOffice in the
 *   same server (config exment.file_server_soffice_path); otherwise download only
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

    /** The answer of makeOnView() while the PDF is being made (not kept in the table). */
    public const STATUS_CONVERTING = 'converting';
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
        // an empty file (a new text file) is known by its extension
        if ($mime === 'application/x-empty' || $mime === 'inode/x-empty') {
            $mime = null;
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
     * The converter on the inner network (a Gotenberg with LibreOffice), set
     * on the server only: http(s), a host and a port, nothing else. Its user
     * and password (when the converter asks for them: Basic) are not in it.
     */
    public static function sofficeUrl(): ?string
    {
        $parts = static::converterParts();
        return isset($parts) ? $parts['url'] : null;
    }

    /**
     * The address of the converter and its user and password, from
     * "http(s)://[user:password@]host[:port][/]".
     *
     * @return array{url: string, user: ?string, password: ?string}|null
     */
    protected static function converterParts(): ?array
    {
        $url = config('exment.file_server_soffice_url');
        if (!is_string($url) || trim($url) === '' || preg_match('/\s/', trim($url)) === 1) {
            return null;
        }
        $parts = parse_url(trim($url));
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['query']) || isset($parts['fragment'])
            || (isset($parts['path']) && $parts['path'] !== '/')) {
            return null;
        }
        return [
            'url' => strtolower($parts['scheme']) . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''),
            'user' => isset($parts['user']) ? rawurldecode($parts['user']) : null,
            'password' => isset($parts['pass']) ? rawurldecode($parts['pass']) : null,
        ];
    }

    /**
     * Whether the site can make the PDF of an Office file (a converter or LibreOffice).
     */
    public static function canConvertOffice(): bool
    {
        return static::sofficeUrl() !== null || static::sofficePath() !== null;
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
            || ($kind === static::KIND_OFFICE && static::canConvertOffice());
        if (!$needs) {
            $version->rendition_status = FileNodeVersion::RENDITION_NONE;
            $version->save();
            return;
        }
        // with a converter of its own, an Office file is converted the first time its
        // preview is opened (makeOnView), not while it is uploaded (ISS01704)
        if (static::convertsOnView($kind)) {
            return;
        }
        try {
            FileServerRenditionJob::dispatch($version->id);
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('[FileServer] could not queue the preview of version ' . $version->id . ': ' . $e->getMessage());
        }
    }

    /**
     * Whether the PDF of the kind is made when the preview is first opened:
     * an Office file with a converter of its own (EXMENT_FILE_SERVER_SOFFICE_URL).
     * When the site has a queue worker (ISS01236), this can go to the queue.
     */
    public static function convertsOnView(string $kind): bool
    {
        return $kind === static::KIND_OFFICE && static::sofficeUrl() !== null;
    }

    /**
     * The preview of a version opened: makes its PDF now when it is not made
     * yet and none is being made. One request converts; the others (another
     * viewer, the same page asking again) are told it is being made, by a lock
     * of the version that ends by itself after the time limit.
     *
     * @return string the status: done, failed, none, held, or "converting"
     */
    public static function makeOnView(FileNodeVersion $version): string
    {
        $node = $version->node;
        if (!isset($node) || !static::convertsOnView(static::kind($version->mime_type, $node->name))) {
            return strval($version->rendition_status ?? static::STATUS_CONVERTING);
        }
        if ($version->rendition_status !== null) {
            return strval($version->rendition_status);
        }
        $timeout = max(5, intval(config('exment.file_server_soffice_timeout', 120)));
        $lock = \Illuminate\Support\Facades\Cache::lock('exment_file_server_convert_' . $version->id, $timeout + 60);
        if (!$lock->get()) {
            return static::STATUS_CONVERTING;
        }
        try {
            $version->refresh();
            if ($version->rendition_status === null) {
                // the viewer may leave the page: the conversion still ends and is kept
                ignore_user_abort(true);
                @set_time_limit($timeout + 30);
                static::makeRenditions($version);
                $version->refresh();
            }
        } finally {
            $lock->release();
        }
        return strval($version->rendition_status ?? static::STATUS_CONVERTING);
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
        if (static::maxBytes() > 0 && intval($version->size) > static::maxBytes()) {
            // too large to convert (ISS00410): the file is still there to download
            $version->rendition_status = $status;
            $version->save();
            return;
        }
        try {
            $local = $library->downloadToLocal($version);
            $dir = dirname($version->path);

            if ($kind === static::KIND_OFFICE && static::canConvertOffice()) {
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
            \Illuminate\Support\Facades\Log::warning('[FileServer] could not make the preview of version ' . $version->id . ': ' . $e->getMessage());
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
    /**
     * The largest image a thumbnail is made of (0: no limit).
     */
    public static function maxPixels(): int
    {
        return max(0, intval(config('exment.file_server_preview_max_pixels', 20000000)));
    }

    /**
     * The largest file made into a preview by Imagick or LibreOffice (0: no limit).
     */
    public static function maxBytes(): int
    {
        return max(0, intval(config('exment.file_server_preview_max_bytes', 104857600)));
    }

    public static function imageThumbnail(string $local): ?string
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        // a huge image would use up the memory (ISS00410)
        $info = @getimagesize($local);
        if ($info === false || (static::maxPixels() > 0 && intval($info[0]) * intval($info[1]) > static::maxPixels())) {
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
            // read as a PDF only (not as what its bytes say: MVG, SVG, ...)
            $im->readImage('pdf:' . $local . '[0]');
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
        if (static::sofficeUrl() !== null) {
            return static::convertByConverter($local, $ext);
        }
        $soffice = static::sofficePath();
        if (!isset($soffice)) {
            return null;
        }
        $work = Library::tempPath('conv_');
        @mkdir($work, 0777, true);
        $input = $work . DIRECTORY_SEPARATOR . 'input.' . preg_replace('/[^a-z0-9]/', '', $ext);
        copy($local, $input);
        // a list (no shell between): nothing of the names can be run
        $command = [$soffice, '--headless', '--norestore', '--convert-to', 'pdf', '--outdir', $work, $input,
            '-env:UserInstallation=file://' . str_replace('\\', '/', $work) . '/profile'];
        // run without the network, when the site gives a command for it (config file_server_soffice_prefix)
        $prefix = trim(strval(config('exment.file_server_soffice_prefix', '')));
        if ($prefix !== '') {
            $command = array_merge(preg_split('/\s+/', $prefix) ?: [], $command);
        }
        $timeout = intval(config('exment.file_server_soffice_timeout', 120));
        $process = new \Symfony\Component\Process\Process($command, $work, ['HOME' => $work]);
        $process->setTimeout($timeout);
        $process->run();
        $pdf = $work . DIRECTORY_SEPARATOR . 'input.pdf';
        $result = null;
        if (is_file($pdf)) {
            $result = Library::tempPath('pdf_');
            rename($pdf, $result);
        }
        \Illuminate\Support\Facades\File::deleteDirectory($work);
        return $result;
    }

    /**
     * Convert an Office file to PDF with the converter on the inner network
     * (Gotenberg: POST /forms/libreoffice/convert). Only the file is sent, with
     * a plain name; the converter keeps nothing. While it is busy (its queue
     * full: 429, or starting: 503) it is asked again a few times, within the
     * time limit. It is not followed to another address (no redirect), and
     * its answer is written to a file as it comes, up to a limit. Returns the
     * local path of the PDF, or null.
     */
    protected static function convertByConverter(string $local, string $ext): ?string
    {
        $parts = static::converterParts();
        if (!isset($parts)) {
            return null;
        }
        $url = $parts['url'];
        $maxPdf = max(1048576, intval(config('exment.file_server_soffice_max_pdf_bytes', 209715200)));
        $ext = preg_replace('/[^a-z0-9]/', '', strtolower($ext)) ?: 'bin';
        $timeout = max(5, intval(config('exment.file_server_soffice_timeout', 120)));
        $until = microtime(true) + $timeout;
        $wait = 2;
        while (true) {
            $left = (int) ceil($until - microtime(true));
            if ($left <= 0) {
                return null;
            }
            $stream = fopen($local, 'rb');
            if ($stream === false) {
                return null;
            }
            try {
                $request = \Illuminate\Support\Facades\Http::timeout($left)
                    ->connectTimeout(min(10, $left))
                    // not to another address; the answer read as it comes
                    ->withOptions(['allow_redirects' => false, 'stream' => true])
                    ->accept('application/pdf');
                if (isset($parts['user'])) {
                    $request = $request->withBasicAuth($parts['user'], strval($parts['password']));
                }
                $response = $request->attach('files', $stream, 'input.' . $ext)
                    ->post($url . '/forms/libreoffice/convert');
            } catch (\Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('[FileServer] the converter could not be reached: ' . $e->getMessage());
                return null;
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
            $status = $response->status();
            if (($status === 429 || $status === 503) && microtime(true) + $wait < $until) {
                // busy: asked again after a while
                sleep($wait);
                $wait = min($wait * 2, 16);
                continue;
            }
            if ($status !== 200 || intval($response->header('Content-Length') ?: 0) > $maxPdf) {
                \Illuminate\Support\Facades\Log::warning('[FileServer] the converter answered ' . $status . ' for a .' . $ext . ' file');
                return null;
            }
            return static::receivePdf($response->toPsrResponse()->getBody(), $maxPdf, $ext);
        }
    }

    /**
     * Write the answer of the converter to a local file as it comes; stop past
     * the limit, or when it is not a PDF. Returns the path, or null.
     */
    protected static function receivePdf(\Psr\Http\Message\StreamInterface $body, int $max, string $ext): ?string
    {
        $result = Library::tempPath('pdf_');
        $out = fopen($result, 'wb');
        if ($out === false) {
            return null;
        }
        $written = 0;
        $ok = true;
        try {
            while (!$body->eof()) {
                $chunk = $body->read(65536);
                if ($chunk === '') {
                    break;
                }
                $written += strlen($chunk);
                if ($written > $max) {
                    $ok = false;
                    break;
                }
                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            $ok = false;
        } finally {
            fclose($out);
            $body->close();
        }
        if ($ok && strval(file_get_contents($result, false, null, 0, 5)) !== '%PDF-') {
            $ok = false;
        }
        if (!$ok) {
            \Illuminate\Support\Facades\Log::warning('[FileServer] the answer of the converter for a .' . $ext . ' file was not a PDF in ' . $max . ' bytes');
            @unlink($result);
            return null;
        }
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
     * The buttons that open a file in another viewer or editor (a plugin adds
     * them). An opener may give "embed_url": a page to show in the preview of
     * the file (read only), when the server cannot make a PDF of it.
     *
     * @return array<int, array{label: string, url: string, icon?: string, embed_url?: string|null}>
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
                $list[] = [
                    'label' => $opener['label'],
                    'url' => $opener['url'],
                    'icon' => is_string($opener['icon'] ?? null) ? $opener['icon'] : 'fa-external-link',
                    'embed_url' => is_string($opener['embed_url'] ?? null) ? $opener['embed_url'] : null,
                ];
            }
        }
        return $list;
    }
}
