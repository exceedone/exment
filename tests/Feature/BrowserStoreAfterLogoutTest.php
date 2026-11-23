<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Form\Navbar\NotifyNav;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Services\UserApiKeys;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * What the browser keeps for a user (the navbar, the Studio app) and the cache keys of the
 * navbar.
 */
class BrowserStoreAfterLogoutTest extends FeatureTestBase
{
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testTheLoginPageAfterTheLogoutClearsTheStore(): void
    {
        $marker = "key.indexOf('exment.navbar.') === 0";

        // the login page by itself: nothing cleared
        $this->get(admin_url('auth/login'))->assertOk()->assertDontSee($marker, false);

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->get(admin_url('auth/logout'))->assertRedirect();
        $page = $this->get(admin_url('auth/login'));
        $page->assertOk();
        $page->assertSee($marker, false);
        $page->assertSee("indexedDB.deleteDatabase('exment-app')", false);
        $page->assertSee("k.indexOf('exment-app') === 0", false);

        // once only
        $this->get(admin_url('auth/login'))->assertOk()->assertDontSee($marker, false);
    }

    public function testTheStudioStoreIsKeyedByTheUserKey(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $page = $this->get(admin_url('app'));
        $page->assertOk();
        $page->assertSee('var userKey = ' . json_encode(NotifyNav::userKey()) . ';', false);
    }

    public function testTheNavbarCacheKeysCarryTheSite(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $hash = UserApiKeys::siteHash();
        $this->assertStringStartsWith('exment_notify_nav_' . $hash . '_', strval(NotifyNavbar::navbarCacheKey()));
        $this->assertStringStartsWith('exment_workflow_task_nav_' . $hash . '_', strval(WorkflowTaskService::navbarCacheKey()));
    }
}
