<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomOperationColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\DefaultTableProvider;
use Exceedone\Exment\Services\DataImportExport\Providers\Import\LoginUserProvider as LoginUserImportProvider;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an administrator may change the user record or the login of an
 * administrator, on every path: the edit page opened by its URL, the API, an
 * import, an operation and the login accounts page (admin/loginuser) with its
 * import.
 *
 * v6.2.15 only hid the Edit button of that row (LoginUserItem::setEditDelete):
 * a data administrator (user1) could still rename the administrator from the
 * edit page or the API, and a user manager (login_user permission) could give
 * the administrator a new password.
 *
 * What hangs off the record - its child records, attachments and documents -
 * and a login import row that changes nothing stay as on v6.2.15.
 *
 * Every request is sent as ajax: a refusal then answers 403 instead of ending
 * in exit (Permission::error -> Pjax::respond), which would stop PHPUnit
 * without a failure.
 */
class AdministratorUserLockTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        // permissions and role groups are cached for the request; the rollback does not clear that
        System::clearCache();
        parent::tearDown();
    }

    // fixtures -----------------------------------------------------------

    private function userTable(): CustomTable
    {
        return CustomTable::getEloquent(SystemTableName::USER);
    }

    private function loginAs(string $login_id): void
    {
        $login_user = LoginUser::find($login_id);
        $this->assertNotNull($login_user, "login user {$login_id} exists");
        $this->be($login_user);
        System::clearRequestSession();
    }

    /**
     * The user record of a login user, read from the database.
     */
    private function userOf(string $login_id): CustomValue
    {
        System::clearRequestSession();
        $login_user = LoginUser::find($login_id);
        $this->assertNotNull($login_user, "login user {$login_id} exists");
        $user = $this->userTable()->getValueModel()->query()->withoutGlobalScopes()->find($login_user->base_user_id);
        $this->assertInstanceOf(CustomValue::class, $user);

        return $user;
    }

    /**
     * The password hash of a user's own login (not one of a login provider).
     */
    private function passwordOf(CustomValue $user): ?string
    {
        $password = LoginUser::where('base_user_id', $user->id)->whereNull('login_provider')->value('password');

        return is_null($password) ? null : strval($password);
    }

    /**
     * Make a user a member of the template's "User and Organization manage
     * Group": its system permission login_user opens the login accounts page,
     * and it may edit every user. It does not make the user an administrator.
     */
    private function grantUserManager(string $login_id): void
    {
        $role_group = RoleGroup::where('role_group_name', 'user_organization_admin_group')->first();
        $this->assertNotNull($role_group, 'the template has the user and organization manage group');

        $member = new RoleGroupUserOrganization();
        $member->role_group_id = $role_group->id;
        $member->role_group_user_org_type = SystemTableName::USER;
        $member->role_group_target_id = $this->userOf($login_id)->id;
        $member->save();
        System::clearCache();
    }

    /**
     * PUT a change of a user through the data API, the way an API client
     * does: without the key of the list screens.
     *
     * @param array<string, mixed> $value
     * @return int status code
     */
    private function apiUpdate(CustomValue $user, array $value): int
    {
        $table_name = SystemTableName::USER;
        $request = Request::create("/api/data/{$table_name}/{$user->id}", 'PUT', ['value' => $value]);
        $this->app->instance('request', $request);
        $result = (new ApiDataController($this->userTable(), $request))->dataUpdate($request, $table_name, $user->id);

        return $result instanceof Response ? $result->getStatusCode() : 200;
    }

    /**
     * What the login accounts page posts to give a user a new password.
     *
     * @return array<string, string>
     */
    private function newPasswordForm(string $password): array
    {
        return [
            'use_loginuser' => '1',
            'reset_password' => '1',
            'create_password_auto' => '0',
            'password' => $password,
            'password_confirmation' => $password,
            'password_reset_flg' => '0',
            'send_password' => '0',
        ];
    }

    /**
     * A login accounts file: the key column, a label line, then one row that
     * gives the user a new password.
     */
    private function loginFile(string $key, string $value, string $password): UploadedFile
    {
        $csv = implode("\n", [
            "{$key},use_loginuser,create_password_auto,password,send_password,password_reset_flg",
            'Key,Login,Auto password,Password,Send password,Reset flag',
            "{$value},1,0,{$password},0,0",
        ]) . "\n";

        return UploadedFile::fake()->createWithContent('loginusers.csv', $csv);
    }

    // the user record ----------------------------------------------------

    public function testDataAdminCannotOpenOrSaveTheAdministratorsEditPage(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $before = [$admin->getValue('user_name'), $admin->getValue('email')];

        $this->get(admin_url("data/user/{$admin->id}/edit"), self::AJAX)->assertStatus(403);

        System::clearRequestSession();
        $this->put(admin_url("data/user/{$admin->id}"), [
            'value' => ['user_name' => 'renamed by user1', 'email' => 'renamed-by-user1@example.com'],
        ], self::AJAX)->assertStatus(403);

        $after = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame($before, [$after->getValue('user_name'), $after->getValue('email')], 'name and e-mail address are unchanged');
    }

    public function testDataAdminCannotChangeTheAdministratorThroughTheApi(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $before = [$admin->getValue('user_name'), $admin->getValue('email')];

        $this->assertSame(403, $this->apiUpdate($admin, ['user_name' => 'renamed by user1', 'email' => 'renamed-by-user1@example.com']));

        $after = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame($before, [$after->getValue('user_name'), $after->getValue('email')], 'name and e-mail address are unchanged');
    }

    public function testDataAdminStillChangesOtherUsers(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->assertTrue($user2->enableEdit(true), 'the edit page of another user opens');
        $this->assertSame(200, $this->apiUpdate($user2, ['user_name' => 'renamed by user1']));
        $this->assertSame('renamed by user1', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('user_name'));
    }

    public function testAdministratorStillChangesTheAdministrator(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->assertTrue($admin->enableEdit(true), 'the edit page opens for an administrator');
        $this->assertSame(200, $this->apiUpdate($admin, ['user_name' => 'renamed by admin']));
        $this->assertSame('renamed by admin', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->getValue('user_name'));
    }

    public function testWhatHangsOffTheAdministratorStaysAsOnV6(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        // the record's own values are refused...
        $this->assertEquals(ErrorCode::PERMISSION_DENY(), $admin->enableEdit(true));
        // ...its child records, attachments and documents are not
        $this->assertTrue($admin->enableEdit(true, false), 'child records and the add button of the show page');
        $this->assertTrue($admin->enableEdit(false, false), 'attachments and documents');
    }

    public function testDataAdminCannotImportTheAdministrator(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $header = [
            0 => ['id', 'value.user_code', 'value.user_name', 'value.email'],
            1 => ['ID', 'User code', 'User name', 'E-mail'],
        ];
        $rowOf = function (string $login_id): array {
            $user = $this->userOf($login_id);
            return [$user->id, $user->getValue('user_code'), 'renamed by an import', $user->getValue('email')];
        };

        // the administrator's row is refused, so the file is not imported
        $provider = new DefaultTableProvider(['custom_table' => $this->userTable(), 'primary_key' => 'id']);
        [$accepted, $errors] = $provider->validateImportData($provider->getDataObject($header + [2 => $rowOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN)], []));
        $this->assertCount(0, $accepted);
        $this->assertStringContainsString(ErrorCode::PERMISSION_DENY()->getMessage(), implode("\n", $errors));

        // the row of another user is accepted
        $provider = new DefaultTableProvider(['custom_table' => $this->userTable(), 'primary_key' => 'id']);
        [$accepted, $errors] = $provider->validateImportData($provider->getDataObject($header + [2 => $rowOf(TestDefine::TESTDATA_USER_LOGINID_USER2)], []));
        $this->assertSame([], $errors);
        $this->assertCount(1, $accepted);
    }

    public function testDataAdminCannotRunAnOperationOnTheAdministrator(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $table = $this->userTable();
        $user_name = CustomColumn::getEloquent('user_name', $table);
        $this->assertNotNull($user_name);
        $operation = CustomOperation::create([
            'custom_table_id' => $table->id,
            'operation_type' => [CustomOperationType::BULK_UPDATE],
            'operation_name' => 'rename users',
            'options' => ['condition_join' => 'and'],
        ]);
        CustomOperationColumn::create([
            'custom_operation_id' => $operation->id,
            'view_column_type' => ConditionType::COLUMN,
            'view_column_target_id' => $user_name->id,
            'update_value_text' => 'renamed by an operation',
            'options' => ['operation_update_type' => 'default'],
        ]);

        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $names = function (): array {
            return [
                $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->getValue('user_name'),
                $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('user_name'),
            ];
        };
        $before = $names();

        // the administrator alone, or together with another user: nobody is renamed
        $deny = ErrorCode::PERMISSION_DENY()->getMessage();
        $this->assertSame($deny, $operation->execute($table, strval($admin->id)));
        System::clearRequestSession();
        $this->assertSame($deny, $operation->execute($table, "{$admin->id},{$user2->id}"));
        $this->assertSame($before, $names(), 'no user was renamed');

        // another user alone is renamed
        System::clearRequestSession();
        $this->assertTrue($operation->execute($table, strval($user2->id)));
        $this->assertSame('renamed by an operation', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('user_name'));
    }

    // the login (admin/loginuser) ----------------------------------------

    public function testUserManagerCannotChangeTheAdministratorsLogin(): void
    {
        $this->grantUserManager(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $before = $this->passwordOf($admin);
        $this->assertNotNull($before, 'the administrator has a login');

        // the list offers no Edit button on the administrator's row, only on the others
        $this->get(admin_url('loginuser'), self::AJAX)->assertOk()
            ->assertDontSee("loginuser/{$admin->id}/edit", false)
            ->assertSee("loginuser/{$user1->id}/edit", false);

        // the edit page and the save refuse it: a new password, or no login at all
        System::clearRequestSession();
        $this->get(admin_url("loginuser/{$admin->id}/edit"), self::AJAX)->assertStatus(403);
        foreach ([$this->newPasswordForm('Takeover-Passw0rd1'), ['use_loginuser' => '0']] as $form) {
            System::clearRequestSession();
            $this->put(admin_url("loginuser/{$admin->id}"), $form, self::AJAX)->assertStatus(403);
        }
        $this->assertSame($before, $this->passwordOf($admin), 'the administrator keeps the login and its password');

        // the login of another user is still changed
        System::clearRequestSession();
        $this->put(admin_url("loginuser/{$user1->id}"), $this->newPasswordForm('Manager-Passw0rd1'), self::AJAX)
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertTrue(Hash::check('Manager-Passw0rd1', strval($this->passwordOf($user1))));
    }

    public function testUserManagerCannotImportTheAdministratorsLogin(): void
    {
        $this->grantUserManager(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user1 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $before = $this->passwordOf($admin);

        // named by the id, or by the user code
        foreach (['id' => strval($admin->id), 'value.user_code' => strval($admin->getValue('user_code'))] as $key => $value) {
            System::clearRequestSession();
            $response = $this->post(admin_url('loginuser/import'), [
                'select_primary_key' => $key,
                'custom_table_file' => $this->loginFile($key, $value, 'Takeover-Passw0rd1'),
            ], self::AJAX)->assertStatus(400)->assertJson(['result' => false]);
            $this->assertStringContainsString(ErrorCode::PERMISSION_DENY()->getMessage(), strval($response->json('errors.import_error_message.message')), "the row named by {$key} is refused");
        }
        $this->assertSame($before, $this->passwordOf($admin), 'the administrator keeps the password');

        // a row of an exported list keeps the login and gives no new password: it passes, as on
        // v6.2.15; a new password, a generated one or no login at all is refused
        $provider = new LoginUserImportProvider(['primary_key' => 'id']);
        $unchanged = [
            'id' => strval($admin->id),
            'use_loginuser' => '1',
            'create_password_auto' => null,
            'password' => null,
            'send_password' => null,
            'password_reset_flg' => null,
        ];
        $this->assertTrue($provider->validateDataRow(0, ['data' => $unchanged, 'model' => $admin]));
        foreach ([['password' => 'Takeover-Passw0rd1'], ['create_password_auto' => '1'], ['use_loginuser' => '0']] as $change) {
            $errors = $provider->validateDataRow(0, ['data' => array_merge($unchanged, $change), 'model' => $admin]);
            $this->assertIsArray($errors, json_encode($change) ?: '');
            $this->assertStringContainsString(ErrorCode::PERMISSION_DENY()->getMessage(), implode("\n", $errors));
        }

        // the login of another user is still imported
        System::clearRequestSession();
        $this->post(admin_url('loginuser/import'), [
            'select_primary_key' => 'id',
            'custom_table_file' => $this->loginFile('id', strval($user1->id), 'Import-Passw0rd1'),
        ], self::AJAX)->assertOk()->assertJson(['result' => true]);
        $this->assertTrue(Hash::check('Import-Passw0rd1', strval($this->passwordOf($user1))));
    }
}
