<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\System;
use Illuminate\Support\Str;

/**
 * Shows the text of a comment as Markdown: headings, lists, bold, code, links, tables and
 * images (GitHub Flavored Markdown).
 *
 * - Html written in a comment is never sent as html: it is escaped and read as the text it is.
 * - What Markdown made is cleaned by html_clean(), the cleaner of the editor column, so no
 *   script, no on* attribute and no "javascript:" url is left.
 * - A line break stays a line break.
 * - A comment written before this came is not read as Markdown at all: see commentHtml().
 * - An image is shown only when it is a file of this Exment (see MarkdownFileImage). An image
 *   of another site becomes a link: opening a comment must not call another site.
 */
class CommentMarkdown
{
    /**
     * The html of a saved comment.
     *
     * A comment written before Markdown comments came (System::comment_markdown_start) was
     * written as plain text, where "100*2*3", a line of "---" or a line that starts with "#"
     * or "+" mean nothing special. Read as Markdown they would lose those characters or turn
     * into headings, so such a comment is shown exactly as it always was.
     *
     * @param \Exceedone\Exment\Model\CustomValue $comment a data of the comment table
     * @return string html, safe to send as it is
     */
    public static function commentHtml($comment): string
    {
        $text = $comment->getValue('comment_detail');
        if (is_array($text)) {
            $text = implode("\n", $text);
        }

        if (static::isBeforeMarkdown($comment->created_at)) {
            return strval(replaceBreakEsc(strval($text)));
        }

        return static::toHtml($text);
    }

    /**
     * Whether a comment made at this time is older than Markdown comments.
     *
     * @param mixed $created_at
     * @return bool
     */
    public static function isBeforeMarkdown($created_at): bool
    {
        $start = System::comment_markdown_start();
        if (is_nullorempty($start) || is_nullorempty($created_at)) {
            return false;
        }

        try {
            return \Carbon\Carbon::parse($created_at)->lt(\Carbon\Carbon::parse($start));
        } catch (\Throwable $ex) {
            return false;
        }
    }

    /**
     * @param string|null $text the comment as it was written
     * @return string html, safe to send as it is
     */
    public static function toHtml($text): string
    {
        if (is_nullorempty($text)) {
            return '';
        }
        $text = strval($text);

        try {
            $html = Str::markdown($text, [
                'html_input' => 'escape',
                'allow_unsafe_links' => false,
                'max_nesting_level' => 30,
                'renderer' => [
                    // a line break of the text is a line break on the screen
                    'soft_break' => "<br />\n",
                ],
            ]);

            $html = static::filterImages($html);
            $html = static::openLinksInNewTab($html);

            $cleaned = html_clean($html);
            if (is_string($cleaned) && trim($cleaned) !== '') {
                return $cleaned;
            }
        } catch (\Throwable $ex) {
            // falls to the plain text below
        }

        return replaceBreakEsc($text);
    }

    /**
     * Keep the images that are files of this Exment, and turn the others into links.
     * Markdown is the only thing that writes an <img> here (html of the comment is escaped),
     * and it always writes it as src, alt and title, in this order.
     *
     * @param string $html
     * @return string
     */
    protected static function filterImages(string $html): string
    {
        $result = preg_replace_callback(
            '/<img\s+src="(?<src>[^"]*)"(?:\s+alt="(?<alt>[^"]*)")?(?:\s+title="[^"]*")?\s*\/?>/u',
            function ($matches) {
                $src = html_entity_decode($matches['src'], ENT_QUOTES, 'UTF-8');
                $alt = $matches['alt'] ?? '';

                $uuid = MarkdownFileImage::getFileUuid($src);
                if (!is_null($uuid) && MarkdownFileImage::isImage($uuid)) {
                    return MarkdownFileImage::imgTag($uuid, $alt);
                }

                if ($src === '') {
                    return $alt;
                }
                return '<a href="' . $matches['src'] . '">' . ($alt !== '' ? $alt : $matches['src']) . '</a>';
            },
            $html
        );

        return is_null($result) ? '' : $result;
    }

    /**
     * A link to a site opens in a new tab, so the screen with the comment stays.
     *
     * @param string $html
     * @return string
     */
    protected static function openLinksInNewTab(string $html): string
    {
        $result = preg_replace('/<a href="(https?:\/\/[^"]*)"/u', '<a target="_blank" href="$1"', $html);

        return is_null($result) ? $html : $result;
    }
}
