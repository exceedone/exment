<?php

namespace Exceedone\Exment\Services;

/**
 * The picture of a user without an avatar of their own: a circle in a color of the
 * name, with its initials in white. The same as the avatars of ExmentPM (the same
 * initials, palette and hash), so a person has the same color in both. Drawn as an
 * inline SVG (a data: URL): no image to request, and it goes wherever the URL of an
 * avatar goes (LoginUser / UserTrait::display_avatar).
 */
class InitialsAvatar
{
    /** The colors of ExmentPM's avatars, white letters on each. */
    public const PALETTE = ['#16a085', '#3c8dbc', '#e67e22', '#8e44ad', '#2980b9', '#c0392b', '#27ae60', '#d35400', '#7f8c8d', '#1abc9c'];

    /**
     * Two characters that tell people apart: "Seiji Noro (EX1)" -> "SN",
     * "sysadmin" -> "SY", "野呂 誠司" / "野呂誠司" -> "野呂". A trailing "(EX1)"
     * style company tag is ignored. (ExmentPM's PortalMembership::initials.)
     */
    public static function initials(string $name): string
    {
        $s = trim(preg_replace('/[(（][^)）]*[)）]/u', '', $name) ?? '');
        if ($s === '') {
            $s = trim(preg_replace('/[()（）]/u', '', $name) ?? '');
        }
        if ($s === '') {
            return '?';
        }
        $words = preg_split('/[\s\x{3000}]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) ?: [$s];
        if (preg_match('/^[\p{Latin}\p{N}]/u', $words[0])) {
            if (count($words) >= 2) {
                return mb_strtoupper(mb_substr($words[0], 0, 1) . mb_substr(end($words), 0, 1));
            }
            return mb_strtoupper(mb_substr($words[0], 0, 2));
        }

        return mb_substr($words[0], 0, 2);
    }

    /** The color of a name (ExmentPM's hash). */
    public static function color(string $name): string
    {
        return static::PALETTE[abs(crc32($name)) % count(static::PALETTE)];
    }

    /** The SVG: a 64x64 circle with the initials. */
    public static function svg(string $name): string
    {
        $text = htmlspecialchars(static::initials($name), ENT_QUOTES | ENT_XML1, 'UTF-8');
        $color = static::color($name);
        return '<svg xmlns="http://www.w3.org/2000/svg" width="64" height="64" viewBox="0 0 64 64">'
            . '<circle cx="32" cy="32" r="32" fill="' . $color . '"/>'
            . '<text x="32" y="32" dy=".35em" text-anchor="middle" fill="#fff" font-size="28" font-weight="700" letter-spacing="-1"'
            . ' font-family="-apple-system,BlinkMacSystemFont,&quot;Segoe UI&quot;,&quot;Hiragino Sans&quot;,&quot;Hiragino Kaku Gothic ProN&quot;,Meiryo,&quot;Noto Sans JP&quot;,sans-serif">'
            . $text . '</text></svg>';
    }

    /** The SVG as a URL for an <img>; every character that could end an attribute is escaped. */
    public static function url(string $name): string
    {
        return 'data:image/svg+xml;charset=utf-8,' . rawurlencode(static::svg($name));
    }
}
