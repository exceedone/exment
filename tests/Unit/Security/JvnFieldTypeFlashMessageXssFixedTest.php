<?php

namespace Exceedone\Exment\Tests\Unit\Security;

/**
 * JVN #05318392 (FIXED, second vector): Reflected XSS via the "Field type [...] does not exist."
 * flash message rendered on the カスタム列設定 (Custom Column) form.
 *
 * Attack path (before the fix):
 *   GET  /admin/column/{table}/create?column_type=<img src=x onerror=alert(document.cookie)>
 *   -> CustomColumnController::form() reads request()->get('column_type')
 *      and calls CustomItem::getItem(...) with a fake CustomColumn whose column_type
 *      is that raw payload.
 *   -> CustomItem::getItem() (src/ColumnItems/CustomItem.php) executed:
 *         admin_error('Error', "Field type [$column_type] does not exist.");
 *      which flashed the payload verbatim into the "error" MessageBag.
 *   -> partials/alerts.blade.php renders the flash `message` with {!! !!} (raw)
 *      to preserve legitimate HTML (e.g. update-link banners), so the injected
 *      <img onerror=...> executed in the admin's browser on the very next render.
 *
 *   Evidence in the JVN report (レスポンス抜粋):
 *       <p>Field type [<img src="_" onerror="alert(document.cookie)">] does not exist.</p>
 *
 * The fix (defence at every call site that interpolates a possibly-tainted value
 * into an admin flash message):
 *   CustomItem::getItem()       -> admin_error('Error', 'Field type [' . e($column_type)      . '] does not exist.');
 *   FormOtherItem::getItem()    -> admin_error('Error', 'Field type [' . e($form_column_name) . '] does not exist.');  // hardening
 *   Form::__call()              -> admin_error('Error', 'Field type [' . e($method)           . '] does not exist.');  // hardening
 *
 * We deliberately DO NOT change partials/alerts.blade.php from `{!! !!}` to `{{ }}`,
 * because legitimate callers pass HTML fragments (e.g. Exment DashboardController
 * builds an <a href="admin_url('system')"> update banner via admin_info). The correct
 * layer to escape at is the call site that mixes user input into the message body.
 *
 * BEFORE FIX: the source contains `"Field type [$column_type] does not exist."` (raw
 *             interpolation of the user-controlled value).
 * AFTER FIX:  the source contains `e($column_type)` / `e($form_column_name)` / `e($method)`
 *             at each of the three sinks, so any tag characters are HTML-entity encoded
 *             before the message reaches the raw-blade renderer.
 *
 * NOTE on the Form::__call() sink: it lives in the separate exceedone/laravel-admin package
 * (fix: branch hotfixfeature/jvn-security-fixes-lv10, commit 9a163e41). Until that fix ships
 * in a tagged release and the tagged version pinned by the CI harness
 * (exment-boilerplate/composer.dev.lock, currently v4.0.0) is bumped, the installed vendor
 * copy still carries the raw sink. That sink is NOT reachable with user input from Exment
 * (no `$form->{$userControlled}()` call exists in src/), so the assertion is skipped, not
 * failed, while the hardened laravel-admin release is not yet installed.
 */
class JvnFieldTypeFlashMessageXssFixedTest extends SecurityRegressionTestCase
{
    /** Read a source file that lives under exment/src. */
    private function exmentSrc(string $relativeFromSrc): string
    {
        return $this->exmentSource($relativeFromSrc);
    }

    /** Read a source file from the admin framework package actually used here (exceedone/laravel-admin). */
    private function openAdminSrc(string $relativeFromSrc): string
    {
        $path = base_path('vendor/exceedone/laravel-admin/src/' . ltrim($relativeFromSrc, '/'));
        $this->assertFileExists($path, "Source not found: $path");
        return (string) file_get_contents($path);
    }

    /** Installed version of exceedone/laravel-admin, for the skip message. */
    private function openAdminVersion(): string
    {
        if (class_exists(\Composer\InstalledVersions::class)
            && \Composer\InstalledVersions::isInstalled('exceedone/laravel-admin')) {
            return (string) \Composer\InstalledVersions::getPrettyVersion('exceedone/laravel-admin');
        }

        return 'unknown';
    }

    // -----------------------------------------------------------------------
    // Primary sink from the JVN report — CustomItem::getItem()
    // -----------------------------------------------------------------------

    public function test_custom_item_getitem_escapes_column_type(): void
    {
        $src = $this->exmentSrc('ColumnItems/CustomItem.php');

        $this->assertStringContainsString(
            "e(\$column_type)",
            $src,
            'CustomItem::getItem() must HTML-escape $column_type before flashing it (JVN #05318392).'
        );
    }

    public function test_custom_item_getitem_no_longer_uses_raw_interpolation(): void
    {
        $src = $this->exmentSrc('ColumnItems/CustomItem.php');

        // The exact old sink shape from the JVN report must be gone.
        $this->assertStringNotContainsString(
            '"Field type [$column_type] does not exist."',
            $src,
            'The raw double-quoted interpolation sink from the JVN report must be removed.'
        );
    }

    // -----------------------------------------------------------------------
    // Defence-in-depth siblings — same shape, same channel
    // -----------------------------------------------------------------------

    public function test_form_other_item_getitem_escapes_form_column_name(): void
    {
        $src = $this->exmentSrc('ColumnItems/FormOtherItem.php');

        $this->assertStringContainsString(
            "e(\$form_column_name)",
            $src,
            'FormOtherItem::getItem() must escape $form_column_name before flashing.'
        );
        $this->assertStringNotContainsString(
            '"Field type [$form_column_name] does not exist."',
            $src,
            'The raw double-quoted interpolation sink must be removed.'
        );
    }

    public function test_open_admin_form_call_escapes_method(): void
    {
        $src = $this->openAdminSrc('Form.php');

        // Companion fix is in exceedone/laravel-admin (commit 9a163e41) and not yet in the
        // tagged release installed by CI. Skip (not fail) until the hardened release lands.
        if (!str_contains($src, 'e($method)')) {
            $this->markTestSkipped(
                'Installed exceedone/laravel-admin (' . $this->openAdminVersion() . ') does not yet contain '
                . 'the Form::__call() hardening (laravel-admin commit 9a163e41, branch hotfixfeature/jvn-security-fixes-lv10).'
            );
        }

        $this->assertStringContainsString(
            "e(\$method)",
            $src,
            'Form::__call() must escape $method before flashing.'
        );
        $this->assertStringNotContainsString(
            '"Field type [$method] does not exist."',
            $src,
            'The raw double-quoted interpolation sink must be removed.'
        );
    }

    // -----------------------------------------------------------------------
    // Runtime proof: the escape helper produces the expected output
    // -----------------------------------------------------------------------

    public function test_escape_helper_neutralises_the_reported_payload(): void
    {
        // The exact payload from the JVN #05318392 report.
        $payload = '<img src=_ onerror=alert(document.cookie)>';

        $escaped = e($payload);

        // With `<` and `>` entity-encoded the browser cannot construct any element,
        // so the literal word "onerror" inside a text node is inert.
        $this->assertStringNotContainsString('<img',  $escaped, 'Tag start must be entity-encoded.');
        $this->assertStringNotContainsString('<',     $escaped, 'No raw "<" may remain in the escaped payload.');
        $this->assertStringNotContainsString('>',     $escaped, 'No raw ">" may remain in the escaped payload.');
        $this->assertStringContainsString('&lt;img',  $escaped, 'Escaped payload should start with the entity for <.');
        $this->assertStringContainsString('&gt;',     $escaped, 'Trailing ">" must be entity-encoded too.');
    }
}
