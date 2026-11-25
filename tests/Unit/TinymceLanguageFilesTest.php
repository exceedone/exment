<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;

/**
 * The language files of TinyMCE Exment ships (public/vendor/exment/tinymce/langs, from tinymce-i18n).
 *
 * An empty string is not "no translation" for TinyMCE: the button gets an empty name. In ko-KR the
 * buttons of the block, the font, the font size and the colours had no aria-label (ISS02894). The
 * empty strings were filled; a newer copy of the files must not bring them back.
 */
class TinymceLanguageFilesTest extends TestCase
{
    /**
     * @return array<string, string> language => contents
     */
    protected function files(): array
    {
        $files = [];
        foreach (glob(exment_package_path('public/vendor/exment/tinymce/langs') . '/*.js') ?: [] as $file) {
            $files[basename($file, '.js')] = (string) file_get_contents($file);
        }
        return $files;
    }

    public function testTheLanguagesExmentShips(): void
    {
        $this->assertSame(['de', 'es', 'ja', 'ko-KR', 'vi', 'zh-CN'], array_keys($this->files()));
    }

    public function testNoStringIsEmpty(): void
    {
        foreach ($this->files() as $lang => $src) {
            $this->assertStringStartsWith('tinymce.addI18n("' . $lang . '", {', ltrim($src), $lang);
            $this->assertSame(0, preg_match_all('/^\s*"(?:[^"\\\\]|\\\\.)*":\s*"",?\s*$/m', $src, $m), $lang . ': ' . implode(', ', $m[0] ?? []));
        }
    }

    public function testTheToolbarOfKoreanHasItsNames(): void
    {
        $src = $this->files()['ko-KR'];
        foreach (['Block {0}', 'Font {0}', 'Font size {0}', 'Text color {0}', 'Background color {0}'] as $key) {
            $this->assertMatchesRegularExpression('/^\s*' . preg_quote(json_encode($key), '/') . ':\s*"[^"]+",?\s*$/m', $src, $key);
        }
    }
}
