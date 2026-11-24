<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Services\UserLocale;

/**
 * Every installed language (each folder of resources/lang) has every text of ja (ISS02835).
 * As the command exment:checklang, which lists the missing keys.
 */
class LangKeysTest extends UnitTestBase
{
    public function testEveryInstalledLocaleHasTheKeysOfJa(): void
    {
        $ja = require exment_package_path('resources/lang/ja/exment.php');
        $this->assertIsArray($ja);
        $jaKeys = array_keys(array_dot($ja));

        $missing = [];
        foreach (UserLocale::installed() as $locale) {
            $path = exment_package_path("resources/lang/$locale/exment.php");
            if ($locale === 'ja' || !is_file($path)) {
                continue;
            }
            $lang = require $path;
            $this->assertIsArray($lang, "$locale/exment.php");
            $flat = array_dot($lang);
            foreach ($jaKeys as $key) {
                if (!array_key_exists($key, $flat)) {
                    $missing[$locale][] = $key;
                }
            }
        }

        $report = collect($missing)->map(function ($keys, $locale) {
            return "$locale: " . count($keys) . " missing (" . implode(', ', array_slice($keys, 0, 20)) . (count($keys) > 20 ? ', ...' : '') . ')';
        })->implode("\n");
        $this->assertEmpty($missing, "missing keys (php artisan exment:checklang lists all):\n" . $report);
    }
}
