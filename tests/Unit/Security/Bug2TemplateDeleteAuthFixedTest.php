<?php

namespace Exceedone\Exment\Tests\Unit\Security;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Enums\Permission;

/**
 * BUG-2 (FIXED): template deletion was reachable without authentication.
 *
 * Fix: src/Controllers/TemplateController.php::delete()
 *      Once the system is installed (System::initialized()) it requires a logged-in user
 *      with Permission::SYSTEM, otherwise -> abort(403).
 *      (Before initialization there is no user yet; the install wizard stays anonymous.)
 *      `template/search` is intentionally kept anonymous: used by the install wizard and only lists metadata.
 */
class Bug2TemplateDeleteAuthFixedTest extends SecurityRegressionTestCase
{
    private function prefix(): string
    {
        return config('admin.route.prefix', 'admin');
    }

    public function test_unauthenticated_delete_is_forbidden(): void
    {
        // The `api/...` path has no session and no CSRF -> matches the anonymous-attacker scenario.
        $prefix = $this->prefix();

        $response = $this->delete("/$prefix/api/template/delete", [
            'template' => 'attacker_' . uniqid(),
        ]);

        $this->assertSame(
            403,
            $response->getStatusCode(),
            'BUG-2 fixed: an anonymous template delete (installed system) must be 403.'
        );
    }

    public function test_source_has_system_permission_guard(): void
    {
        $src = $this->exmentSource('Controllers/TemplateController.php');

        $this->assertStringContainsString('System::initialized()', $src);
        $this->assertStringContainsString('hasPermission(Permission::SYSTEM)', $src);
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*System::initialized\(\)\s*\)\s*\{.*?hasPermission\(Permission::SYSTEM\).*?abort\(403\);/s',
            $src,
            'delete() must keep the SYSTEM permission guard when initialized.'
        );
    }

    public function test_authenticated_system_admin_can_still_delete(): void
    {
        // No regression: an admin with SYSTEM permission can still delete (unknown template name -> no-op, 200).
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
            ->delete("/$prefix/webapi/template/delete", [
                'template' => 'nonexistent_' . uniqid(),
            ]);

        $this->assertNotSame(
            403,
            $response->getStatusCode(),
            'An admin with SYSTEM permission must NOT be blocked by the guard (no regression).'
        );
    }
}
