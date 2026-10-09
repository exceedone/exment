<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Form\Field\ViewOnly;

/**
 * Lint guard for bug class (1): array values reaching Blade {{ }} (-> e() -> htmlspecialchars()).
 *
 * Multi-value custom columns (select / select_table / user / organization / file / image with
 * options.multiple_enabled = 1) hold PHP arrays. A form-field blade that echoes a raw $value or
 * $default (e.g. value="{{$value}}" or {{ $value }}) throws TypeError on PHP 8 when that value is
 * an array. display.blade.php (ViewOnly) and init_only.blade.php (InitOnly) were fixed to flatten
 * arrays; this static lint fails CI if any field blade reintroduces the raw pattern WITHOUT an
 * array guard, so a future dev cannot silently re-create the bug.
 *
 * Pure file scan: no DB, no rendering.
 */
class FieldBladeArrayGuardTest extends TestCase
{
    /**
     * Resolve the package's resources/views/form/field directory from a package class location,
     * independent of install path / symlinks.
     *
     * @return string
     */
    private function fieldViewDir(): string
    {
        $ref = new \ReflectionClass(ViewOnly::class); // src/Form/Field/ViewOnly.php
        $fileName = $ref->getFileName();
        if ($fileName === false) {
            $this->fail('Cannot resolve the file of class ' . ViewOnly::class);
        }
        $packageRoot = dirname($fileName, 4); // -> package root
        return $packageRoot . '/resources/views/form/field';
    }

    /**
     * @return void
     */
    public function testNoFieldBladeEchoesRawArrayValueWithoutGuard()
    {
        $dir = $this->fieldViewDir();
        $this->assertDirectoryExists($dir, "Field view directory not found: {$dir}");

        $files = glob($dir . '/*.blade.php');
        if ($files === false) {
            $this->fail("Cannot list blade templates in: {$dir}");
        }
        $this->assertNotEmpty($files, 'No field blade templates found to lint.');

        // The dangerous sink: a STANDALONE echo of $value / $default (the multi-value column value).
        // Expression forms such as {{ $value === null ? 'checked' : '' }} are intentionally NOT
        // matched (they don't pass the array to htmlspecialchars).
        $rawSinkPatterns = [
            '/\{\{\s*\$(value|default)\s*\}\}/',                 // {{ $value }} / {{$default}}
            '/value\s*=\s*"\{\{\s*\$(value|default)\s*\}\}"/',   // value="{{$value}}"
        ];

        $offenders = [];
        foreach ($files as $file) {
            $content = file_get_contents($file);
            if ($content === false) {
                $this->fail("Cannot read blade file: {$file}");
            }

            $hasRawSink = false;
            foreach ($rawSinkPatterns as $p) {
                if (preg_match($p, $content)) {
                    $hasRawSink = true;
                    break;
                }
            }
            if (!$hasRawSink) {
                continue;
            }

            // A blade that echoes the raw value is only safe if it normalises arrays first.
            $hasArrayGuard = (strpos($content, 'is_array(') !== false)
                || (strpos($content, 'Arr::flatten(') !== false);

            if (!$hasArrayGuard) {
                $offenders[] = basename($file);
            }
        }

        $this->assertSame(
            [],
            $offenders,
            "These field blade(s) echo a raw \$value/\$default that may be an array (multi-value column) "
            . "without an is_array()/Arr::flatten() guard -> htmlspecialchars(array) TypeError on PHP 8. "
            . "Flatten arrays like display.blade.php does: " . implode(', ', $offenders)
        );
    }
}
