<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LineAccountLink;
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

    public function testRunAsDoesNotInheritPermissionsCachedForPreviousUser(): void
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

    public function testRunAsLeavesNoPermissionCacheBehindForNextUser(): void
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);

        LineActingUser::runAs($user2, function () {
            return $this->canViewSafetyTable();
        });

        $this->be($admin, 'admin');
        $this->assertTrue($this->canViewSafetyTable(), 'After runAs() the next user must not see user2\'s cached permissions.');
    }

    public function testUserIdIsNullOnceTheLinkedUserIsDeletedAndBackAfterRestore(): void
    {
        $user = $this->createUser();
        LineAccountLink::forUser((int) $user->id)->markLinked('Uactingsoftdelete');
        $this->assertSame((int) $user->id, LineActingUser::userId('Uactingsoftdelete'));

        $user->delete();
        $this->assertNull(LineActingUser::userId('Uactingsoftdelete'), 'The LINE account of a deleted user must not act for them.');

        CustomTable::getEloquent(SystemTableName::USER)->getValueModel()
            ->withTrashed()->whereKey($user->id)->firstOrFail()->restore();
        $this->assertSame((int) $user->id, LineActingUser::userId('Uactingsoftdelete'), 'A soft delete keeps the link, so a restore brings it back.');
    }

    public function testForceDeletingUserRemovesItsLineLink(): void
    {
        $user = $this->createUser();
        LineAccountLink::forUser((int) $user->id)->markLinked('Uactingforcedelete');

        $user->forceDelete();

        $this->assertFalse(LineAccountLink::where('user_id', $user->id)->exists());
        $this->assertFalse(LineAccountLink::where('line_user_id', 'Uactingforcedelete')->exists());
    }

    protected function createUser(): CustomValue
    {
        $code = 'lineacting_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code,
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        return $user;
    }
}
