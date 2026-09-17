<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

class LineLinkPageTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function loginAndReset(string $loginUserId): LoginUser
    {
        /** @var LoginUser $loginUser */
        $loginUser = LoginUser::find($loginUserId);
        $this->assertNotNull($loginUser, "Fixture: login user {$loginUserId} is required.");
        LineAccountLink::where('user_id', $loginUser->base_user_id)->delete();
        $this->be($loginUser, 'admin');
        return $loginUser;
    }

    public function testEmployeeCanOpenLineLinkPage()
    {
        $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $response = $this->get('admin/line/link');

        $response->assertStatus(200);
        $response->assertSee('line/link/generate');
    }

    public function testLineLinkMenuVisibleForEmployee()
    {
        $loginUser = $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->assertTrue(
            $loginUser->visible('line/link'),
            'The LINE連携 menu must be visible to a regular user (not a system admin).'
        );
    }

    public function testAdminCanOpenLineLinkPage()
    {
        $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $response = $this->get('admin/line/link');

        $response->assertStatus(200);
        $response->assertSee('line/link/generate');
    }
    public function testGenerateRefusedWhenOaBasicIdNotConfigured()
    {
        $loginUser = $this->loginAndReset(TestDefine::TESTDATA_USER_LOGINID_USER2);
        config(['exment.line.oa_basic_id' => null]);
        System::system_line_oa_basic_id('');
        System::clearCache();

        $response = $this->post('admin/line/link/generate');

        $response->assertStatus(302);
        $link = LineAccountLink::forUser((int) $loginUser->base_user_id);
        $this->assertFalse($link->hasActiveCode(), 'No link code may be issued while the OA basic id is missing.');
    }
}
