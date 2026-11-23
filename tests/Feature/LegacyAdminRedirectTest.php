<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Providers\RouteServiceProvider;
use Exceedone\Exment\Services\LegacyAdminRedirect;
use Exceedone\Exment\Services\Installer\InstallingForm;
use Exceedone\Exment\Tests\InstalledStateTrait;
use Illuminate\Support\ViewErrorBag;

/**
 * A new install has no admin prefix (7.2.1). A site that had "/admin" and
 * now has none sends the old links "/admin/..." to the same page of the same
 * site (GET only). A site with a prefix is not touched.
 */
class LegacyAdminRedirectTest extends FeatureTestBase
{
    use InstalledStateTrait;

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

    public function testTheSetupPageTakesATypedPrefix(): void
    {
        $this->assertSame('', LegacyAdminRedirect::normalizePrefix(''));
        $this->assertSame('', LegacyAdminRedirect::normalizePrefix(null));
        $this->assertSame('', LegacyAdminRedirect::normalizePrefix(' / '));
        $this->assertSame('admin', LegacyAdminRedirect::normalizePrefix('admin'));
        $this->assertSame('admin', LegacyAdminRedirect::normalizePrefix('/admin/'));
        $this->assertSame('exment-2_a', LegacyAdminRedirect::normalizePrefix('exment-2_a'));
        // one path segment of plain characters only
        $this->assertNull(LegacyAdminRedirect::normalizePrefix('a/b'));
        $this->assertNull(LegacyAdminRedirect::normalizePrefix('ad min'));
        $this->assertNull(LegacyAdminRedirect::normalizePrefix('admin#x'));
        $this->assertNull(LegacyAdminRedirect::normalizePrefix('../admin'));
        $this->assertNull(LegacyAdminRedirect::normalizePrefix('管理'));
        $this->assertNull(LegacyAdminRedirect::normalizePrefix(str_repeat('a', 65)));
    }

    public function testTheSetupPageShowsTheField(): void
    {
        $html = view('exment::install.installing')->with('errors', new ViewErrorBag())->render();
        $this->assertStringContainsString('name="ADMIN_ROUTE_PREFIX"', $html);
        $this->assertStringContainsString(exmtrans('install.installing.admin_route_prefix'), $html);
    }

    public function testABadPrefixIsRefusedBeforeAnythingIsWritten(): void
    {
        $path = base_path('.env');
        $before = (string) file_get_contents($path);
        request()->merge(['ADMIN_ROUTE_PREFIX' => 'a/b']);
        // a site not installed yet (an installed one refuses the form at once)
        $this->useInstalledState(false);
        try {
            $response = (new InstallingForm())->post();
        } finally {
            $this->restoreInstalledState();
        }
        $this->assertSame(302, $response->getStatusCode());
        $this->assertTrue(session()->has('errors'));
        $this->assertSame($before, (string) file_get_contents($path), '.env is not touched');
    }
}
