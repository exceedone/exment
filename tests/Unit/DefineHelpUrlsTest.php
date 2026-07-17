<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Define;

/**
 * Test cases: Define::HELP_URLS definition for right-top manual link.
 *
 * JS (common.js ToggleHelp) loops HELP_URLS and uses the FIRST entry whose "uri"
 * prefix-matches the current pathname. If the entry has "query", it matches only
 * when the url has that query string parameter (ex. table?qrcodesetting).
 * Therefore an entry with "query" MUST be placed before any entry without "query"
 * whose uri is the same (or a prefix), otherwise the "query" entry is never reached.
 */
class DefineHelpUrlsTest extends UnitTestBase
{
    /**
     * All entries have required keys, and "query" is not empty when set.
     *
     * @return void
     */
    public function testAllEntriesHaveRequiredKeys()
    {
        foreach (Define::HELP_URLS as $index => $help) {
            $this->assertArrayHasKey('uri', $help, "HELP_URLS[{$index}] has no 'uri'.");
            $this->assertArrayHasKey('help_uri', $help, "HELP_URLS[{$index}] has no 'help_uri'.");
            $this->assertTrue(strlen($help['help_uri']) > 0, "HELP_URLS[{$index}] 'help_uri' is empty.");

            if (array_key_exists('query', $help)) {
                $this->assertTrue(strlen($help['query']) > 0, "HELP_URLS[{$index}] 'query' is empty.");
            }
        }
    }

    /**
     * QR code setting page (table?qrcodesetting) has its own help entry.
     *
     * @return void
     */
    public function testContainsQrCodeSettingEntry()
    {
        $this->assertTrue($this->hasEntry('table', '2d_barcode', 'qrcodesetting'), 'qrcodesetting help entry is not contained.');
    }

    /**
     * JAN code setting page (table?jancodesetting) has its own help entry.
     *
     * @return void
     */
    public function testContainsJanCodeSettingEntry()
    {
        $this->assertTrue($this->hasEntry('table', 'jancode', 'jancodesetting'), 'jancodesetting help entry is not contained.');
    }

    /**
     * Operation log page (auth/logs) has its own help entry.
     *
     * @return void
     */
    public function testContainsAuthLogsEntry()
    {
        $this->assertTrue($this->hasEntry('auth/logs', 'logs'), 'auth/logs help entry is not contained.');
    }

    /**
     * Notify page has its own help entry.
     *
     * @return void
     */
    public function testContainsNotifyEntry()
    {
        $this->assertTrue($this->hasEntry('notify', 'notify'), 'notify help entry is not contained.');
    }

    /**
     * Entries with "query" are placed before entries without "query" whose uri
     * prefix-matches. If shadowed, JS first-match-wins never reaches the query entry.
     *
     * @return void
     */
    public function testQueryEntriesAreNotShadowed()
    {
        $helps = Define::HELP_URLS;
        foreach ($helps as $i => $help) {
            if (!array_key_exists('query', $help)) {
                continue;
            }
            // check all prior entries without query
            for ($j = 0; $j < $i; $j++) {
                $prior = $helps[$j];
                if (array_key_exists('query', $prior)) {
                    continue;
                }
                // '/' and '' match exactly in JS, never shadow prefix entries
                if (in_array(trim($prior['uri'], '/'), ['', null], true)) {
                    continue;
                }
                $this->assertFalse(
                    strpos($help['uri'], $prior['uri']) === 0,
                    "HELP_URLS[{$i}] (uri={$help['uri']}, query={$help['query']}) is shadowed by HELP_URLS[{$j}] (uri={$prior['uri']}). Move the query entry before it."
                );
            }
        }
    }

    /**
     * News link is updated to the new category url.
     *
     * @return void
     */
    public function testNewsLinkUpdated()
    {
        $this->assertMatch(Define::EXMENT_NEWS_LINK, 'https://exment.net/category/news');
    }

    /**
     * Whether HELP_URLS has the entry.
     *
     * @param string $uri
     * @param string $help_uri
     * @param string|null $query
     * @return bool
     */
    protected function hasEntry($uri, $help_uri, $query = null)
    {
        foreach (Define::HELP_URLS as $help) {
            if (array_get($help, 'uri') !== $uri) {
                continue;
            }
            if (array_get($help, 'help_uri') !== $help_uri) {
                continue;
            }
            if (array_get($help, 'query') !== $query) {
                continue;
            }
            return true;
        }
        return false;
    }
}
