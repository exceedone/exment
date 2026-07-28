<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * JVN #58391472 / #92835104 (FIXED): Stored XSS in the navbar-notification detail page.
 *
 * The audit could not map these two XSS reports to a location; this is the confirmed
 * site. NotifyNavbarController::show() rendered the "target_custom_value" Show field as:
 *
 *     $show->field('target_custom_value', ...)->as(function ($v) use ($custom_value) {
 *         return $custom_value->getLabel();
 *     })->setEscape(false);        // <-- disables Blade auto-escaping
 *
 * getLabel() returns a PLAIN-TEXT label built from the parent record's label columns
 * (raw _text() of Text/Textarea columns — no escaping). A low-privileged user who can
 * create/edit such a record stores e.g. `<img src=x onerror=...>` in the label column;
 * when the target/admin user opens /admin/notify_navbar/{id}, setEscape(false) emits the
 * label raw and the script runs in the victim's session (cross-user stored XSS, High).
 *
 * The fix: drop ->setEscape(false) for target_custom_value so the Show field HTML-escapes
 * the plain-text label. (The adjacent notify_body field legitimately keeps setEscape(false)
 * because it emits html_clean()-sanitized HTML — that must NOT be removed.)
 *
 * BEFORE FIX: the target_custom_value block ends with ->setEscape(false).
 * AFTER FIX:  it does not; notify_body still sanitizes with html_clean() + setEscape(false).
 */
class JvnNotifyNavbarLabelXssFixedTest extends SecurityRegressionTestCase
{
    /** Return the source slice for a given $show->field('<name>' ... up to the next $show->field(. */
    private function fieldBlock(string $src, string $fieldName): string
    {
        $start = strpos($src, "'$fieldName'");
        $this->assertNotFalse($start, "Field '$fieldName' not found in NotifyNavbarController.");

        $next = strpos($src, '$show->field(', $start + 1);
        $end  = $next === false ? strlen($src) : $next;

        return substr($src, $start, $end - $start);
    }

    public function test_target_custom_value_no_longer_disables_escaping(): void
    {
        $src   = $this->exmentSource('Controllers/NotifyNavbarController.php');
        $block = $this->fieldBlock($src, 'target_custom_value');

        $this->assertStringNotContainsString(
            'setEscape(false)',
            $block,
            'target_custom_value must NOT disable escaping — its plain-text label must be HTML-escaped (JVN #58391472/#92835104).'
        );
        $this->assertStringContainsString(
            'getLabel()',
            $block,
            'Sanity: the target_custom_value field still renders the record label.'
        );
    }

    public function test_notify_body_still_sanitizes_html(): void
    {
        // Regression guard the OTHER direction: the legitimate rich-HTML field must keep its sanitizer.
        $src   = $this->exmentSource('Controllers/NotifyNavbarController.php');
        $block = $this->fieldBlock($src, 'notify_body');

        $this->assertStringContainsString('html_clean(', $block, 'notify_body must still be sanitized via html_clean().');
        $this->assertStringContainsString('setEscape(false)', $block, 'notify_body legitimately keeps setEscape(false) for sanitized HTML.');
    }

    public function test_blade_escaping_neutralizes_label_payload(): void
    {
        // Demonstrates the mechanism the fix relies on: once setEscape(false) is removed,
        // the Show field renders the label through Blade auto-escaping (e()), which neutralizes it.
        $payload = '<img src=x onerror=alert(document.cookie)>';

        $escaped = e($payload);

        $this->assertStringNotContainsString('<img', $escaped, 'Auto-escaping must neutralize the stored label payload.');
        $this->assertStringContainsString('&lt;img', $escaped, 'The payload must be rendered as inert text.');
    }
}
