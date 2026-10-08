<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use PHPUnit\Framework\Attributes\DataProvider;

/**
 * XSS hardening (defense-in-depth) for two sinks surfaced while investigating the JVN XSS reports.
 * Both were verified as not currently exploitable (existing input validation / dead code), so these
 * are regression guards for the added defensive layers rather than active-exploit reproductions.
 *
 *  A) CustomColumns/Url.php::_html() rendered the stored value as an <a href> without scheme
 *     filtering. The 'url' validation rule blocks javascript:/data:/vbscript: on write, but the
 *     display layer now also rejects those schemes (rewrites to '#') as a second line of defense.
 *
 *  B) resources/views/form/field/link.blade.php emitted the href with the RAW Blade operator
 *     `<a href="{!! $old !!}">`, an unescaped attribute sink. Changed to `{{ $old }}` so quotes
 *     and angle-brackets are entity-encoded (no attribute breakout).
 */
class JvnUrlLinkXssHardeningTest extends SecurityRegressionTestCase
{
    /** Read a file from the package root (outside /src). */
    private function exmentFile(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . ltrim($relative, '/');
        $this->assertFileExists($path, "File not found: $path");
        return (string) file_get_contents($path);
    }

    // -----------------------------------------------------------------------
    // A) Url column scheme filter
    // -----------------------------------------------------------------------

    public function test_url_source_has_scheme_filter(): void
    {
        $src = $this->exmentSource('ColumnItems/CustomColumns/Url.php');

        $this->assertMatchesRegularExpression(
            '/preg_match\(\s*[\'"]\/\^\\\\s\*\(javascript\|data\|vbscript\)/i',
            $src,
            'Url::_html() must reject javascript:/data:/vbscript: schemes.'
        );
    }

    /**
     * Verify the exact deny-list predicate used in the fix against a battery of inputs:
     * dangerous schemes are neutralized, legitimate URLs are left intact.
     */
    #[DataProvider('urlSchemeProvider')]
    public function test_scheme_filter_predicate(string $url, bool $shouldBlock): void
    {
        // Mirror of the guard in Url::_html().
        $blocked = is_string($url) && preg_match('/^\s*(javascript|data|vbscript):/i', $url) === 1;

        $this->assertSame($shouldBlock, $blocked, "Scheme decision for [$url] must match the fix.");
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function urlSchemeProvider(): array
    {
        return [
            'plain javascript'   => ['javascript:alert(1)', true],
            'mixed-case js'      => ['JaVaScRiPt:alert(1)', true],
            'leading spaces js'  => ['   javascript:alert(1)', true],
            'data uri'           => ['data:text/html,<script>alert(1)</script>', true],
            'vbscript'           => ['vbscript:msgbox(1)', true],
            'https link'         => ['https://example.com/path?a=1&b=2', false],
            'http link'          => ['http://example.com', false],
            'mailto'             => ['mailto:user@example.com', false],
            'relative path'      => ['/admin/home', false],
        ];
    }

    // -----------------------------------------------------------------------
    // B) link form-field blade escaping
    // -----------------------------------------------------------------------

    public function test_link_blade_escapes_href_attribute(): void
    {
        $blade = $this->exmentFile('resources/views/form/field/link.blade.php');

        $this->assertStringNotContainsString(
            '{!! $old !!}',
            $blade,
            'The link field href must not use the raw Blade operator (attribute-injection sink).'
        );
        $this->assertStringContainsString(
            'href="{{ $old }}"',
            $blade,
            'The link field href must be rendered with escaping ({{ $old }}).'
        );
    }
}
