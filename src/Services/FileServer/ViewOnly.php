<?php

namespace Exceedone\Exment\Services\FileServer;

use Exceedone\Exment\Model\FileNodeVersion;

/**
 * Who may see a file and not download it (the download rule of the workspace, a
 * share link without download): the page shows what is made from the file, never
 * the file itself.
 * - an image: a copy made smaller (the original bytes and their metadata stay)
 * - Office, and a PDF that has one: the PDF the converter made (the watermark of
 *   the workspace is drawn over it in the page)
 * - text, video, audio, a PDF without one: "the download is not allowed"
 */
class ViewOnly
{
    /** the longest side of the image shown */
    public const IMAGE_SIZE = 1600;

    /**
     * The kind of preview the page draws for who may not download.
     */
    public static function kind(string $kind, ?FileNodeVersion $version): string
    {
        switch ($kind) {
            case Preview::KIND_IMAGE:
            case Preview::KIND_OFFICE:
                return $kind;
            case Preview::KIND_PDF:
                // its own converted PDF only (drawn as an Office file is)
                return isset($version, $version->preview_path) ? Preview::KIND_OFFICE : Preview::KIND_NONE;
        }
        return Preview::KIND_NONE;
    }

    /**
     * An image made smaller and written again: [bytes, mime], or null (GD missing, not an image).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function image(FileNodeVersion $version): ?array
    {
        if (!function_exists('imagecreatefromstring')) {
            return null;
        }
        try {
            $bytes = Library::disk()->get($version->path);
            if (!is_string($bytes) || $bytes === '') {
                return null;
            }
            $src = @imagecreatefromstring($bytes);
            if ($src === false) {
                return null;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = min(1, static::IMAGE_SIZE / max($w, $h, 1));
            $nw = max(1, intval(round($w * $scale)));
            $nh = max(1, intval(round($h * $scale)));
            $dst = imagecreatetruecolor($nw, $nh);
            $png = in_array(strtolower(strval($version->mime_type)), ['image/png', 'image/gif', 'image/webp'], true);
            if ($png) {
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
            }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            ob_start();
            $png ? imagepng($dst, null, 6) : imagejpeg($dst, null, 85);
            $out = strval(ob_get_clean());
            imagedestroy($src);
            imagedestroy($dst);
            return $out !== '' ? [$out, $png ? 'image/png' : 'image/jpeg'] : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * The answer for the bytes of a file to someone who may not download it: an image
     * made smaller, else "the download is not allowed".
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public static function response(FileNodeVersion $version, string $name)
    {
        if (Preview::kind($version->mime_type, $name) === Preview::KIND_IMAGE && ($image = static::image($version)) !== null) {
            return response($image[0], 200, [
                'Content-Type' => $image[1],
                'X-Content-Type-Options' => 'nosniff',
                'Cache-Control' => 'private, no-store',
                'Content-Security-Policy' => "sandbox; default-src 'none'",
            ]);
        }
        return response(
            '<!doctype html><meta charset="utf-8"><p style="font-family:sans-serif;color:#666;padding:12px">' . e(exmtrans('file_server.preview_none_no_download')) . '</p>',
            403,
            ['Content-Type' => 'text/html; charset=utf-8', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'"]
        );
    }
}
