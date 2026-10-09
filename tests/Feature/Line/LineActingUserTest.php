<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Line\LineActingUser;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

class LineActingUserTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
    }

    protected function canViewSafetyTable(): bool
    {
        return CustomTable::getEloquent('safety_check_event')->hasPermission();
    }

    public function testRunAsDoesNotInheritPermissionsCachedForPreviousUser()
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->be($admin, 'admin');
        $this->assertTrue($this->canViewSafetyTable());

        $seenAsUser2 = LineActingUser::runAs($user2, function () {
            return $this->canViewSafetyTable();
        });

        $this->assertFalse($seenAsUser2, 'user2 must be evaluated with their own permissions, not the admin set cached earlier in the request.');
    }

    public function testRunAsLeavesNoPermissionCacheBehindForNextUser()
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);

        LineActingUser::runAs($user2, function () {
            return $this->canViewSafetyTable();
        });

        $this->be($admin, 'admin');
        $this->assertTrue($this->canViewSafetyTable(), 'After runAs() the next user must not see user2\'s cached permissions.');
    }
}
