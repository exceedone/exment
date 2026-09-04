<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;

/**
 * Guard test for bug class (2): locale files getting out of sync.
 *
 * PR #1675 shipped resources/lang_vendor/en/validation.php containing only a single key
 * (`unique_in_table`) while the ja counterpart had the full set. Because lang_vendor is
 * published into the app lang path, that incomplete file shadowed every other English
 * validation message (required / email / numeric ...), turning them into raw "validation.*"
 * keys.
 *
 * This test asserts that, for every shipped lang group file, all locales expose exactly the
 * same set of (flattened) keys. It fails if a dev adds a key to one locale but forgets the
 * other, or ships a partial locale file.
 */
class TranslationKeyParityTest extends TestCase
{
    /**
     * Lang roots shipped by the package, relative to the package root.
     *
     * @var array<string>
     */
    private $langRoots = [
        'resources/lang',
        'resources/lang_vendor',
    ];

    /**
     * @return void
     */
    public function testLocaleFilesHaveIdenticalKeySets()
    {
        $packageRoot = dirname(__DIR__, 2);
        $checked = 0;

        foreach ($this->langRoots as $relRoot) {
            $root = $packageRoot . '/' . $relRoot;
            if (!is_dir($root)) {
                continue;
            }

            // collect locales (sub directories: en, ja, ...)
            $entries = scandir($root);
            $this->assertNotFalse($entries, "Cannot scan lang root: {$relRoot}");
            $locales = array_values(array_filter($entries, function ($d) use ($root) {
                return $d !== '.' && $d !== '..' && is_dir($root . '/' . $d);
            }));
            $this->assertGreaterThanOrEqual(2, count($locales), "Expected at least 2 locales under {$relRoot}");

            // collect the union of all *.php files across locales
            $files = [];
            foreach ($locales as $locale) {
                foreach (glob($root . '/' . $locale . '/*.php') ?: [] as $path) {
                    $files[basename($path)] = true;
                }
            }

            foreach (array_keys($files) as $file) {
                // build flattened key set per locale for this file
                $keysByLocale = [];
                foreach ($locales as $locale) {
                    $path = $root . '/' . $locale . '/' . $file;
                    $this->assertFileExists($path, "Lang file missing for locale '{$locale}': {$relRoot}/{$locale}/{$file}");
                    $arr = require $path;
                    $this->assertIsArray($arr, "Lang file did not return an array: {$relRoot}/{$locale}/{$file}");
                    $keysByLocale[$locale] = array_keys($this->flatten($arr));
                }

                // compare every locale against the union of all keys
                $allKeys = [];
                foreach ($keysByLocale as $keys) {
                    $allKeys = array_merge($allKeys, $keys);
                }
                $allKeys = array_values(array_unique($allKeys));

                foreach ($keysByLocale as $locale => $keys) {
                    $missing = array_values(array_diff($allKeys, $keys));
                    $this->assertSame(
                        [],
                        $missing,
                        sprintf(
                            "Locale '%s' file '%s/%s' is missing %d key(s): %s",
                            $locale,
                            $relRoot,
                            $file,
                            count($missing),
                            implode(', ', array_slice($missing, 0, 20)) . (count($missing) > 20 ? ' ...' : '')
                        )
                    );
                }

                $checked++;
            }
        }

        $this->assertGreaterThan(0, $checked, 'No lang files were checked - resolve paths.');
    }

    /**
     * Flatten a nested array into dot-keyed scalars.
     *
     * @param array<mixed> $arr
     * @param string $prefix
     * @return array<string,mixed>
     */
    private function flatten(array $arr, string $prefix = ''): array
    {
        $out = [];
        foreach ($arr as $key => $value) {
            $dotKey = $prefix === '' ? (string)$key : $prefix . '.' . $key;
            if (is_array($value)) {
                $out = array_merge($out, $this->flatten($value, $dotKey));
            } else {
                $out[$dotKey] = $value;
            }
        }
        return $out;
    }
}
