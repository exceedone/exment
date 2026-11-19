<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * JVN #92835104 (FIXED): CSS Injection -> stored XSS via a public form's Custom CSS field.
 *
 * The reporter described an unvalidated "Custom CSS" input. In Exment the field is the
 * public form's `custom_css` option (Public Form settings -> CSS/JS tab). It was emitted
 * raw into a <style> block by BootstrapPublicForm::setPublicFormCssJs():
 *
 *     if (!is_null($css = $public_form->getOption("custom_css"))) {
 *         Ad::style($css);            // <-- raw, no sanitizing
 *     }
 *
 * Custom CSS is intentional, but the value must stay INSIDE the <style> element. Because
 * "</style" is the only sequence that ends a raw-text <style> element, a payload like
 *     </style><script>alert(document.cookie)</script><style>
 * broke out of the block and executed as script on the (unauthenticated) public form page
 * -> the documented "CSS injection" escalates to stored XSS (cookie theft / data capture).
 * The injector only needs the table-level custom_form_public permission (grantable to a
 * non-administrator via a role group), so it crosses a real trust boundary.
 *
 * The fix: pass the value through css_clean() at the sink. css_clean() neutralizes every
 * "</" (never valid CSS outside a string; "\/" is a no-op escape inside a CSS string), so
 * legitimate CSS is preserved but HTML/script breakout is impossible. Applying it at the
 * render sink also protects CSS already stored before the fix.
 *
 * BEFORE FIX: BootstrapPublicForm passes custom_css to Ad::style() raw.
 * AFTER FIX:  it passes css_clean($css); css_clean() is defined in Services/Helpers.php.
 */
class JvnPublicFormCustomCssInjectionFixedTest extends SecurityRegressionTestCase
{
    public function test_public_form_sink_sanitizes_custom_css(): void
    {
        $src = $this->exmentSource('Middleware/BootstrapPublicForm.php');

        // The custom_css sink must run css_clean() before Ad::style().
        $this->assertMatchesRegularExpression(
            '/Ad::style\(\s*css_clean\s*\(/',
            $src,
            'custom_css must be sanitized with css_clean() before being emitted into <style> (JVN#92835104).'
        );
        $this->assertStringNotContainsString(
            'Ad::style($css)',
            $src,
            'custom_css must NOT be passed to Ad::style() raw.'
        );
    }

    public function test_css_clean_neutralizes_style_breakout(): void
    {
        $payload = '</style><script>document.title="XSS_PWNED"+document.cookie</script><style>';

        $clean = css_clean($payload);

        // No sequence that can terminate the <style> raw-text element may survive.
        $this->assertStringNotContainsStringIgnoringCase(
            '</style',
            $clean,
            'css_clean() must neutralize the </style> breakout that escalates CSS injection to XSS.'
        );
        $this->assertStringNotContainsString(
            '</',
            $clean,
            'css_clean() must neutralize every "</" end-tag-open sequence.'
        );
    }

    public function test_css_clean_preserves_legitimate_css(): void
    {
        // The reporter's own PoC value and other real CSS must pass through unchanged.
        foreach ([
            'body { background: red !important; }',
            ".login-box { width: 100%; }\n#app > .container { margin: 0 auto; }",
            'a:hover { color: #0052cc; }',
        ] as $css) {
            $this->assertSame($css, css_clean($css), 'Legitimate CSS must be preserved by css_clean().');
        }
    }

    public function test_cleaned_css_cannot_break_out_of_style_block(): void
    {
        // End-to-end mechanism: whatever css_clean() returns, once wrapped in <style> the
        // raw-text element cannot be terminated early, so no markup/script can be injected.
        $payload = '</STYLE><img src=x onerror=alert(1)>';

        $rendered = '<style type="text/css">' . css_clean($payload) . '</style>';

        // The ONLY "</style" in the rendered output is our own trailing close tag.
        $this->assertSame(
            1,
            substr_count(strtolower($rendered), '</style'),
            'A cleaned payload must not introduce an extra </style> that breaks out of the block.'
        );
    }
}
