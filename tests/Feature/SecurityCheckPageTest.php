<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\SystemRequire\Security\SiteProbe;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Http;

/**
 * The page of the security check (ISS02833): the system administrators only, every
 * item shown, a fix that runs and one that is refused.
 */
class SecurityCheckPageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // the site asked about itself: refusals and the headers, without the network
        SiteProbe::clear();
        Http::fake(['*' => Http::response('', 404, ['X-Frame-Options' => 'SAMEORIGIN'])]);
    }

    protected function tearDown(): void
    {
        SiteProbe::clear();
        parent::tearDown();
    }

    public function testTheSystemAdministratorSeesEveryItem(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $page = $this->get(admin_url('security_check'), self::AJAX)->assertOk();
        foreach (['file_permission', 'app_debug', 'app_key', 'app_env', 'https', 'headers', 'exposed_path', 'admin_password', 'two_factor', 'webhook', 'version', 'session_cookie'] as $key) {
            $page->assertSee('data-security="' . $key . '"', false);
        }
    }

    public function testOthersMayNotOpenIt(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $this->get(admin_url('security_check'), self::AJAX)->assertStatus(403);
        $this->post(admin_url('security_check/fix'), ['item' => 'app_debug'], self::AJAX)->assertStatus(403);
    }

    public function testAnItemWithoutAFixIsNotChanged(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['app.key' => '']);
        $this->post(admin_url('security_check/fix'), ['item' => 'app_key'])->assertRedirect(admin_url('security_check'));
        $this->assertSame('', config('app.key'));
    }
}
