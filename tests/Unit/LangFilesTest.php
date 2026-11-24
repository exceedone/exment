<?php

namespace Exceedone\Exment\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Every installed UI language must have the same keys and placeholders as English,
 * so a missing or broken translation is found before it reaches a screen.
 */
class LangFilesTest extends TestCase
{
    protected static function basePath(string $path = ''): string
    {
        return dirname(__DIR__, 2) . ($path === '' ? '' : '/' . $path);
    }

    /**
     * @return array<string, string> locale => directory
     */
    protected static function localeDirs(string $root): array
    {
        $dirs = [];
        foreach (glob(static::basePath($root) . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $dirs[basename($dir)] = $dir;
        }
        ksort($dirs);
        return $dirs;
    }

    /**
     * Flatten a nested translation array to "a.b.c" => value.
     *
     * @param array<mixed> $array
     * @return array<string, mixed>
     */
    protected static function flatten(array $array, string $prefix = ''): array
    {
        $result = [];
        foreach ($array as $key => $value) {
            $path = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($value) && count($value) > 0) {
                $result += static::flatten($value, $path);
            } else {
                $result[$path] = $value;
            }
        }
        return $result;
    }

    /**
     * Placeholders that code replaces: Laravel ":name", sprintf "%s" / "%d", "{0}".
     *
     * @return array<int, string>
     */
    protected static function placeholders(string $text): array
    {
        $text = preg_replace('#[a-z]+://\S+#', ' ', $text) ?? $text;
        $found = [];
        preg_match_all('/(?<![A-Za-z0-9_]):([a-z][a-z0-9_]*)/', $text, $m);
        foreach ($m[1] as $name) {
            $found[] = ':' . $name;
        }
        preg_match_all('/%[0-9$]*[sd]|\{\d+\}/', $text, $m);
        foreach ($m[0] as $name) {
            $found[] = $name;
        }
        $found = array_values(array_unique($found));
        sort($found);
        return $found;
    }

    public function testExmentLangFilesMatchEnglish(): void
    {
        $dirs = static::localeDirs('resources/lang');
        $this->assertArrayHasKey('en', $dirs);

        foreach (glob($dirs['en'] . '/*.php') ?: [] as $enFile) {
            $file = basename($enFile);
            $en = static::flatten(require $enFile);

            foreach ($dirs as $locale => $dir) {
                if ($locale === 'en') {
                    continue;
                }
                $path = "$dir/$file";
                $this->assertFileExists($path, "resources/lang/$locale/$file is missing");
                $trans = static::flatten(require $path);

                $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($trans))), "keys missing in $locale/$file");
                $this->assertSame([], array_values(array_diff(array_keys($trans), array_keys($en))), "extra keys in $locale/$file");

                foreach ($en as $key => $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    $this->assertIsString($trans[$key], "$locale/$file $key is not a string");
                    $this->assertSame(static::placeholders($value), static::placeholders($trans[$key]), "placeholders differ in $locale/$file $key");
                }
            }
        }
    }

    public function testVendorLangFilesMatchEnglish(): void
    {
        $dirs = static::localeDirs('resources/lang_vendor');
        $this->assertArrayHasKey('en', $dirs);

        foreach (glob($dirs['en'] . '/*.php') ?: [] as $enFile) {
            $file = basename($enFile);
            $en = static::flatten(require $enFile);

            foreach ($dirs as $locale => $dir) {
                if ($locale === 'en') {
                    continue;
                }
                $path = "$dir/$file";
                $this->assertFileExists($path, "resources/lang_vendor/$locale/$file is missing");
                $trans = static::flatten(require $path);

                $this->assertSame([], array_values(array_diff(array_keys($en), array_keys($trans))), "keys missing in vendor $locale/$file");
                $this->assertSame([], array_values(array_diff(array_keys($trans), array_keys($en))), "extra keys in vendor $locale/$file");

                // Laravel may fill more placeholders than English uses (e.g. :values), so only English's must be there.
                foreach ($en as $key => $value) {
                    if (!is_string($value)) {
                        continue;
                    }
                    $missing = array_diff(static::placeholders($value), static::placeholders((string)$trans[$key]));
                    $this->assertSame([], array_values($missing), "placeholders missing in vendor $locale/$file $key");
                }
            }
        }
    }

    /**
     * Template lang.json files only translate names and texts; the identifiers
     * (table_name, column_name, suuid, menu_name ...) must be the same in every language.
     */
    public function testTemplateLangFilesHaveSameStructure(): void
    {
        $translatable = [
            'template_view_name', 'description', 'table_view_name', 'column_view_name', 'form_view_name',
            'view_view_name', 'title', 'select_item_valtext', 'help', 'text', 'form_block_view_name',
            'view_column_name', 'dashboard_view_name', 'dashboard_box_view_name', 'true_label', 'false_label',
            'role_group_view_name', 'workflow_view_name', 'placeholder',
        ];

        $langDirs = array_merge(
            glob(static::basePath('templates') . '/*/lang', GLOB_ONLYDIR) ?: [],
            glob(static::basePath('system_template') . '/lang', GLOB_ONLYDIR) ?: []
        );
        $this->assertNotEmpty($langDirs);

        foreach ($langDirs as $langDir) {
            $skeletons = [];
            foreach (glob("$langDir/*/lang.json") ?: [] as $file) {
                $json = json_decode((string)file_get_contents($file), true);
                $this->assertIsArray($json, "$file is not valid JSON");

                $skeleton = [];
                foreach (static::flatten($json) as $key => $value) {
                    $last = substr((string)strrchr('.' . $key, '.'), 1);
                    $skeleton[$key] = in_array($last, $translatable, true) ? '*' : $value;
                }
                $skeletons[$file] = $skeleton;
            }

            $reference = array_key_first($skeletons);
            foreach ($skeletons as $file => $skeleton) {
                $this->assertSame($skeletons[$reference], $skeleton, "$file differs from $reference in keys or identifiers");
            }
        }
    }
}
