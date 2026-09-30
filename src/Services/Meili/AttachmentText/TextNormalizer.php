<?php

namespace Exceedone\Exment\Services\Meili\AttachmentText;

final class TextNormalizer
{
    /**
     * @return array{text:string,char_count:int,truncated:bool}
     */
    public static function normalize(string $text, int $maxCharacters): array
    {
        if (!mb_check_encoding($text, 'UTF-8')) {
            $encoding = mb_detect_encoding($text, ['UTF-8', 'SJIS-win', 'EUC-JP', 'ISO-2022-JP', 'Windows-1252'], true) ?: 'UTF-8';
            $text = mb_convert_encoding($text, 'UTF-8', $encoding);
        }

        $text = preg_replace('/^\xEF\xBB\xBF/u', '', $text) ?? $text;
        // Preserve all printable Unicode, including Japanese and symbols. Only
        // remove control characters which cannot be meaningfully searched.
        $text = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\t ]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
        $text = trim($text);

        $charCount = mb_strlen($text, 'UTF-8');
        $truncated = $charCount > $maxCharacters;
        if ($truncated) {
            $text = mb_substr($text, 0, $maxCharacters, 'UTF-8');
            $charCount = $maxCharacters;
        }

        return [
            'text' => $text,
            'char_count' => $charCount,
            'truncated' => $truncated,
        ];
    }
}
