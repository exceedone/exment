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

/**
 * LineActingUser::runAs() switches the admin guard to the LINE user's LoginUser.
 * Exment caches the current user's role/permission set in System::requestSession
 * under a key WITHOUT a user id ("role"), for the life of the PHP request. LINE
 * batches several events into one webhook request, so two users' postbacks run
 * back to back in the same process: the cache must be reset on every switch or
 * user B is evaluated with user A's permissions.
 */
class LineActingUserTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // safety_check_event: the admin can view it, user2 (role group "user_group")
        // holds no permission on it -- a probe that really goes through the
        // cached allPermissions() (Permission::SYSTEM short-circuits to isAdministrator()).
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

        // warm the request cache with the admin's permission set
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
            return $this->canViewSafetyTable(); // warms the cache as user2
        });

        $this->be($admin, 'admin');
        $this->assertTrue($this->canViewSafetyTable(), 'After runAs() the next user must not see user2\'s cached permissions.');
    }
}
