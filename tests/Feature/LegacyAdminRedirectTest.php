<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Services\LegacyAdminRedirect;

/**
 * A new install has no admin prefix (7.2.1). A site that had "/admin" and
 * now has none sends the old links "/admin/..." to the same page of the same
 * site (GET only). A site with a prefix is not touched.
 */
class LegacyAdminRedirectTest extends FeatureTestBase
{
    public function testTheTargetIsAPathOfThisSite(): void
    {
        $this->assertSame('/', LegacyAdminRedirect::target('admin'));
        $this->assertSame('/', LegacyAdminRedirect::target('admin/'));
        $this->assertSame('/data/information', LegacyAdminRedirect::target('admin/data/information'));
        $this->assertSame('/data/information?page=2&view=x', LegacyAdminRedirect::target('admin/data/information', 'page=2&view=x'));
        $this->assertSame('/admin/x', LegacyAdminRedirect::target('admin/admin/x'), 'only the first segment');
        // never off the site
        $this->assertSame('/evil.example.com/x', LegacyAdminRedirect::target('admin//evil.example.com/x'));
        $this->assertSame('/evil.example.com', LegacyAdminRedirect::target('admin/' . chr(92) . 'evil.example.com'));
        $this->assertSame('/', LegacyAdminRedirect::target('admin/https://evil.example.com'));
        $this->assertSame('/', LegacyAdminRedirect::target('admin/javascript:alert(1)'));
        // not an old admin link
        $this->assertNull(LegacyAdminRedirect::target('administrator'));
        $this->assertNull(LegacyAdminRedirect::target('data/admin/x'));
    }

    public function testASiteWithThePrefixHasNoRedirect(): void
    {
        config(['admin.route.prefix' => 'admin']);
        $this->assertFalse(LegacyAdminRedirect::enabled());
        (new RouteServiceProvider(app()))->mapLegacyAdminRedirect();
        app('router')->getRoutes()->refreshNameLookups();
        $this->assertFalse(app('router')->has('exment.legacy_admin_redirect'));
    }

    public function testASiteWithoutThePrefixRedirectsTheOldLinks(): void
    {
        $prefix = config('admin.route.prefix');
        try {
            config(['admin.route.prefix' => '']);
            $this->assertTrue(LegacyAdminRedirect::enabled());
            (new RouteServiceProvider(app()))->mapLegacyAdminRedirect();
            app('router')->getRoutes()->refreshNameLookups();
            $this->assertTrue(app('router')->has('exment.legacy_admin_redirect'));

            // an old link that no route of this test site (which still has /admin) answers
            $this->get('/admin/zz_legacy_link/x?a=1')->assertStatus(302)->assertRedirect(url('/zz_legacy_link/x?a=1'));
            $this->get('/admin//evil.example.com')->assertRedirect(url('/evil.example.com'));
            // GET only
            $this->post('/admin/zz_legacy_link/x')->assertStatus(405);
        } finally {
            config(['admin.route.prefix' => $prefix]);
        }
    }
}
