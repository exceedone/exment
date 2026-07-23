<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * The LINE self-linking page (admin/line/link) must be accessible to EVERY
 * authenticated user, not just system admins — each user only operates on their
 * own link, so there is no privilege escalation.
 *
 * Two guard layers share the Permission::shouldPass('line') engine:
 * - the admin.permission middleware on the route (403 on failure)
 * - Admin::user()->visible() hides the sidebar menu
 */
class LineLinkPageTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    /** Log in a user via the admin guard and delete any existing link so the page renders the QR-generation form. */
    protected function loginAndReset(string $loginUserId): LoginUser
    {
        /** @var LoginUser $loginUser */
        $loginUser = LoginUser::find($loginUserId);
        $this->assertNotNull($loginUser, "Fixture: login user {$loginUserId} is required.");
        LineAccountLink::where('user_id', $loginUser->base_user_id)->delete();
        $this->be($loginUser, 'admin');
        return $loginUser;
    }

    /** A regular user (user2 - belongs only to user_group) must be able to open the link page. */
    public function testEmployeeCanOpenLineLinkPage()
    {
        $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $response = $this->get('admin/line/link');

        $response->assertStatus(200);
        // marker for the line.link view (QR-generation form) - the deny page does not contain this string
        $response->assertSee('line/link/generate');
    }

    /** Sidebar menu: visible('line/link') must be true for a regular user. */
    public function testLineLinkMenuVisibleForEmployee()
    {
        $loginUser = $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->assertTrue(
            $loginUser->visible('line/link'),
            'The LINE連携 menu must be visible to a regular user (not a system admin).'
        );
    }

    /** Regression: admin can still open the link page as before. */
    public function testAdminCanOpenLineLinkPage()
    {
        $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $response = $this->get('admin/line/link');

        $response->assertStatus(200);
        $response->assertSee('line/link/generate');
    }
}
