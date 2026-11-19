<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\Permission;

/**
 * JVN#20312919 (FIXED): template search/enumeration was reachable without authentication.
 *
 * Original issue (F-06): `POST {prefix}/api/template/search` and `{prefix}/webapi/template/search`
 *   lived in the `adminapi_anonymous` middleware group (NO auth) and returned the template list
 *   (names / descriptions / authors / base64 thumbnails) to any anonymous caller.
 *
 * Fix: src/Controllers/TemplateController.php::searchTemplate()
 *      Once the system is installed (System::initialized()) it requires a logged-in user with
 *      Permission::SYSTEM, otherwise -> abort(403). (Before initialization there is no user yet;
 *      the install wizard stays anonymous.)
 *      The authenticated admin page now lists templates through the session-backed `webapi` route
 *      (see InitializeFormTrait::addTemplateTile), so the guard does not break normal usage.
 *
 * Runs INDIVIDUALLY (see SecurityRegressionTestCase note):
 *   vendor/bin/phpunit exment/tests/Unit/Security/TemplateSearchAuthFixedTest.php
 */
class TemplateSearchAuthFixedTest extends SecurityRegressionTestCase
{
    private function prefix(): string
    {
        return config('admin.route.prefix', 'admin');
    }

    public function test_unauthenticated_search_is_forbidden(): void
    {
        // The `api/...` path has no session and no CSRF -> matches the anonymous-attacker scenario.
        $prefix = $this->prefix();

        $response = $this->post("/$prefix/api/template/search", [
            'name' => 'template',
            'column' => 'template',
        ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'JVN#20312919 fixed: an anonymous template search (installed system) must be 403.'
        );
    }

    public function test_source_has_system_permission_guard(): void
    {
        $src = $this->exmentSource('Controllers/TemplateController.php');

        // The guard must live inside searchTemplate() (not only delete()).
        $start = strpos($src, 'function searchTemplate(');
        $this->assertNotFalse($start, 'TemplateController::searchTemplate() not found.');
        $body = substr($src, $start, 1200);

        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*System::initialized\(\)\s*\)\s*\{.*?hasPermission\(Permission::SYSTEM\).*?abort\(403\);/s',
            $body,
            'searchTemplate() must keep the SYSTEM permission guard when initialized.'
        );
    }

    public function test_authenticated_system_admin_can_still_search(): void
    {
        // No regression: an admin with SYSTEM permission can still list templates via the
        // session-backed webapi route.
        $user = null;
        foreach (LoginUser::query()->limit(30)->get() as $u) {
            try {
                if ($u->hasPermission(Permission::SYSTEM)) {
                    $user = $u;
                    break;
                }
            } catch (\Throwable $e) {
                // skip users with broken roles
            }
        }

        if (!$user) {
            $this->markTestSkipped('No LoginUser with SYSTEM permission in the dev DB to assert no-regression.');
        }

        $prefix = $this->prefix();
        $response = $this->actingAs($user, 'admin')
            ->post("/$prefix/webapi/template/search", [
                'name' => 'template',
                'column' => 'template',
            ]);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'An admin with SYSTEM permission must NOT be blocked by the guard (no regression).'
        );
    }
}
