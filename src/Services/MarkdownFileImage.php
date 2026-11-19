<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\File as ExmentFile;

/**
 * Shows the Markdown image syntax "![alt](url)" as a picture, when the url is a file of
 * Exment itself (the "url" the document API answers: {admin}/files/{uuid}).
 *
 * The body of an editor column and a comment are written through the API as text, and a file
 * attached with POST api/document/{tableKey}/{id} could only be linked, not shown.
 *
 * Only a file url of this Exment is turned into an <img>. Its picture is sent by
 * FileController::download(), which asks for a login and for the permission to the data the
 * file is attached to, so the picture is seen by the users who can see that data and by no
 * one else. A url of another site is left as the text it is.
 *
 * Only PNG, JPEG, GIF and WebP are shown. SVG is left as text: it can hold a script, and
 * Exment already sends it as a download instead of showing it.
 */
class MarkdownFileImage
{
    /**
     * Extensions of the files shown as a picture.
     */
    public const EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp'];

    protected const UUID = '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}';

    /**
     * Replace "![alt](file url)" in a html with an <img>.
     *
     * The html has to be escaped (or be the html of the editor) already: the alt text is taken
     * as html and escaped again only where it is not yet, and nothing else is touched.
     *
     * @param string|null $html
     * @return string|null
     */
    public static function toHtml($html)
    {
        if (is_nullorempty($html) || !is_string($html) || strpos($html, '![') === false) {
            return $html;
        }

        $result = preg_replace_callback(
            '/!\[(?<alt>[^\]\r\n]*)\]\((?<src>[^\s()<>"\']+)\)/u',
            function ($matches) {
                $uuid = static::getFileUuid($matches['src']);
                if (is_null($uuid) || !static::isImage($uuid)) {
                    return $matches[0];
                }

                return static::imgTag($uuid, $matches['alt']);
            },
            $html
        );

        return is_null($result) ? $html : $result;
    }

    /**
     * The <img> of a file of this Exment.
     *
     * @param string $uuid
     * @param string $alt the alt text as html (escaped text, or what the editor wrote)
     * @return string
     */
    public static function imgTag(string $uuid, string $alt): string
    {
        $alt = htmlspecialchars(html_entity_decode(strip_tags($alt), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8');
        $src = htmlspecialchars(admin_urls('files', $uuid), ENT_QUOTES, 'UTF-8');

        return '<img src="' . $src . '" alt="' . $alt . '" style="max-width:100%;height:auto;">';
    }

    /**
     * Get the uuid, if the url is a file url of this Exment.
     * "/files/{uuid}", the path from the root ("/admin/files/{uuid}") and the full url are taken.
     *
     * @param string $src
     * @return string|null
     */
    public static function getFileUuid(string $src): ?string
    {
        if (!preg_match('/\A(?<base>.*?)\/?files\/(?<uuid>' . static::UUID . ')\z/u', $src, $matches)) {
            return null;
        }
        // "xfiles/{uuid}" is not "files/{uuid}"
        if ($matches['base'] !== '' && !str_ends_with($src, '/files/' . $matches['uuid'])) {
            return null;
        }

        $adminUrl = rtrim(admin_url(''), '/');
        $adminPath = rtrim(strval(parse_url($adminUrl, PHP_URL_PATH)), '/');
        $bases = ['', $adminUrl, $adminPath];
        // a body written through a proxy or another host name of the same Exment
        $appUrl = rtrim(strval(config('app.url')), '/');
        if ($appUrl !== '') {
            $bases[] = $appUrl . $adminPath;
        }

        if (!in_array($matches['base'], $bases, true)) {
            return null;
        }

        return strtolower($matches['uuid']);
    }

    /**
     * Whether the file is one shown as a picture.
     *
     * @param string $uuid
     * @return bool
     */
    public static function isImage(string $uuid): bool
    {
        $file = ExmentFile::where('uuid', $uuid)->first();
        if (!isset($file)) {
            return false;
        }

        $ext = strtolower(strval(pathinfo(strval($file->filename), PATHINFO_EXTENSION)));

        return in_array($ext, static::EXTENSIONS, true);
    }
}
