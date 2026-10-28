<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiClientType;
use Exceedone\Exment\Enums\MenuType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\OperationLog;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;

/**
 * Requests no test sent before to the system settings: the login accounts of the users
 * (admin/loginuser), the role groups (admin/role_group), the operation log (admin/auth/logs),
 * the menu (admin/auth/menu, admin/webapi/menu/menutargetvalue) and the edit page of an API
 * client. Each answers a redirect, json or a page; none of them ends in exit().
 */
class UserRoleMenuLogCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        // users, role groups and menus are cached for the request; the rollback does not clear that
        System::clearCache();
        parent::tearDown();
    }

    // LoginUserController (admin/loginuser) ---------------------------------------

    public function testTheUserEditPageCreatesALoginAccount(): void
    {
        $user = $this->createUser('claude_cov_login');

        // the edit page posts every login field; LoginUserItem::saved() creates the account
        $this->put(admin_url("loginuser/{$user->id}"), [
            'use_loginuser' => '1',
            'reset_password' => '1',
            'create_password_auto' => '0',
            'password' => 'Coverage-Passw0rd1',
            'password_confirmation' => 'Coverage-Passw0rd1',
            'password_reset_flg' => '0',
            'send_password' => '0',
        ])->assertRedirect(admin_url('loginuser'))->assertSessionHasNoErrors();

        $login_user = LoginUser::where('base_user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check('Coverage-Passw0rd1', $login_user->password));
    }

    public function testLoginAccountsAreImportedFromACsvFile(): void
    {
        $user = $this->createUser('claude_cov_import');

        // first line: the keys, second line: their labels (skipped), then one row per user
        $csv = implode("\n", [
            'id,use_loginuser,create_password_auto,password,send_password,password_reset_flg',
            'ID,Login,Auto password,Password,Send password,Reset flag',
            "{$user->id},1,0,Coverage-Import-Passw0rd1,0,0",
        ]) . "\n";

        $this->post(admin_url('loginuser/import'), [
            'select_primary_key' => 'id',
            'custom_table_file' => UploadedFile::fake()->createWithContent('loginusers.csv', $csv),
        ])->assertOk()->assertJson(['result' => true, 'toastr' => exmtrans('common.message.import_success')]);

        $login_user = LoginUser::where('base_user_id', $user->id)->firstOrFail();
        $this->assertTrue(Hash::check('Coverage-Import-Passw0rd1', $login_user->password));
    }

    public function testTheLoginUserDeleteRouteSoftDeletesTheUser(): void
    {
        // the list and the form hide the delete button; the route itself deletes the user the
        // same way the user data list does (a soft delete)
        $user = $this->createUser('claude_cov_delete');

        $this->delete(admin_url("loginuser/{$user->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);

        $this->assertSoftDeleted(getDBTableName(SystemTableName::USER), ['id' => $user->id]);
    }

    // RoleGroupController (admin/role_group) --------------------------------------

    public function testRoleGroupCrud(): void
    {
        $this->post(admin_url('role_group'), [
            'role_group_name' => 'claude_cov_role',
            'role_group_view_name' => 'Claude coverage role',
            'description' => 'created by the coverage test',
            'role_group_order' => 9,
        ])->assertRedirect(admin_url('role_group'))->assertSessionHasNoErrors();

        $role_group = RoleGroup::where('role_group_name', 'claude_cov_role')->firstOrFail();
        $this->assertSame('Claude coverage role', $role_group->role_group_view_name);
        $this->assertSame('created by the coverage test', $role_group->description);

        $this->put(admin_url("role_group/{$role_group->id}"), [
            'role_group_view_name' => 'Claude coverage role (put)',
            'role_group_order' => 9,
        ])->assertRedirect(admin_url('role_group'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('role_groups', ['id' => $role_group->id, 'role_group_view_name' => 'Claude coverage role (put)']);

        $this->patch(admin_url("role_group/{$role_group->id}"), [
            'role_group_view_name' => 'Claude coverage role (patch)',
            'role_group_order' => 9,
        ])->assertRedirect(admin_url('role_group'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('role_groups', ['id' => $role_group->id, 'role_group_view_name' => 'Claude coverage role (patch)']);

        $this->delete(admin_url("role_group/{$role_group->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing('role_groups', ['id' => $role_group->id]);
    }

    public function testRoleGroupsAreImportedFromACsvFile(): void
    {
        // RoleGroupController::import() names the sheet after the file: role_group.csv holds
        // the role groups themselves (an empty id creates a new one)
        $csv = implode("\n", [
            'id,role_group_name,role_group_view_name,role_group_order,description',
            'ID,Name,View name,Order,Description',
            ',claude_cov_import_role,Claude coverage imported role,7,imported by the coverage test',
        ]) . "\n";

        $this->post(admin_url('role_group/import'), [
            'select_primary_key' => 'id',
            'custom_table_file' => UploadedFile::fake()->createWithContent('role_group.csv', $csv),
        ])->assertOk()->assertJson(['result' => true, 'toastr' => exmtrans('common.message.import_success')]);

        $this->assertDatabaseHas('role_groups', [
            'role_group_name' => 'claude_cov_import_role',
            'role_group_view_name' => 'Claude coverage imported role',
            'role_group_order' => 7,
        ]);
    }

    // LogController (admin/auth/logs) ---------------------------------------------

    public function testAnOperationLogIsShownAndDeleted(): void
    {
        $log = OperationLog::create([
            'user_id' => TestDefine::TESTDATA_USER_LOGINID_ADMIN,
            'path' => 'admin/claude-coverage/log-path',
            'method' => 'POST',
            'ip' => '127.0.0.1',
            'input' => json_encode(['claude_coverage_key' => 'claude_coverage_value']),
        ]);

        $this->get(admin_url("auth/logs/{$log->id}"))
            ->assertOk()
            ->assertSee('admin/claude-coverage/log-path')
            ->assertSee('claude_coverage_value');

        $this->delete(admin_url("auth/logs/{$log->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing($log->getTable(), ['id' => $log->id]);
    }

    // MenuController (admin/auth/menu, admin/webapi/menu) -------------------------

    public function testAMenuIsDeleted(): void
    {
        $menu = new Menu();
        $menu->parent_id = 0;
        $menu->order = 99;
        $menu->title = 'Claude coverage menu';
        $menu->icon = 'fa-bars';
        $menu->uri = '#';
        $menu->menu_type = MenuType::PARENT_NODE;
        $menu->menu_name = 'claude_cov_menu';
        $menu->save();

        $this->delete(admin_url("auth/menu/{$menu->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing($menu->getTable(), ['id' => $menu->id]);
    }

    public function testTheMenuTargetOfATableFillsTheMenuFields(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        if (!$table instanceof CustomTable) {
            $this->fail('the test data has no ' . TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL . ' table');
        }

        $response = $this->post(admin_url('webapi/menu/menutargetvalue'), [
            'menu_type' => MenuType::TABLE,
            'value' => $table->id,
        ]);
        $response->assertOk()->assertJson([
            'title' => $table->table_view_name,
            'uri' => $table->table_name,
        ]);

        // the menu name is the table name, numbered when a menu already uses it
        $names = Menu::query()->pluck('menu_name')->all();
        $expected = $table->table_name;
        for ($i = 1; in_array($expected, $names, true); $i++) {
            $expected = "{$table->table_name}_{$i}";
        }
        $this->assertSame($expected, $response->json('menu_name'));
    }

    // ApiSettingController (admin/api_setting) ------------------------------------

    public function testTheEditPageOfAnApiClientShowsItsSettings(): void
    {
        $this->post(admin_url('api_setting'), [
            'client_type' => ApiClientType::CLIENT_CREDENTIALS,
            'name' => 'Claude coverage edit client',
            'redirect' => 'http://localhost/claude-coverage-edit',
        ])->assertRedirect();
        $client = ApiClient::where('name', 'Claude coverage edit client')->firstOrFail();

        $this->get(admin_url("api_setting/{$client->id}/edit"))
            ->assertOk()
            ->assertSee('Claude coverage edit client')
            ->assertSee('http://localhost/claude-coverage-edit')
            ->assertSee((string) $client->id);
    }

    /**
     * A user record without a login account.
     */
    private function createUser(string $user_code): CustomValue
    {
        $table = CustomTable::getEloquent(SystemTableName::USER);
        $user = $table instanceof CustomTable ? $table->getValueModel() : null;
        if (!$user instanceof CustomValue) {
            $this->fail('the user table is missing');
        }

        $user->value = [
            'user_code' => $user_code,
            'user_name' => "Claude coverage {$user_code}",
            'email' => "{$user_code}@example.com",
        ];
        $user->saveOrFail();

        return $user;
    }
}
