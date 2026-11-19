<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\ApiDataController;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Grid\Tools\GridInlineEditor;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an administrator may change an administrator's user record from a
 * screen.
 *
 * The user list, show and edit pages hide the Edit button of an
 * administrator from everyone else (LoginUserItem::setEditDelete). The
 * inline editor of the list still drew its pen on that row and saved the
 * change, so a data administrator (user1: custom_value_edit_all) could
 * rename the administrator and change the administrator's e-mail address.
 * Bulk edit and "Set me as" send the same write.
 */
class GridScreenEditAdminUserTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * GridInlineEditor::SCREEN_WRITE_KEY, written out: it is the contract
     * with grid_tools.js.
     */
    private const SCREEN_WRITE = '_grid_edit';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    private function userTable(): CustomTable
    {
        return CustomTable::getEloquent(SystemTableName::USER);
    }

    private function loginAs(string $login_id): void
    {
        $login_user = LoginUser::find($login_id);
        $this->assertNotNull($login_user, "login user {$login_id} exists");
        $this->be($login_user);
    }

    /**
     * The user record of a login user, read from the database.
     */
    private function userOf(string $login_id): CustomValue
    {
        $login_user = LoginUser::find($login_id);
        $this->assertNotNull($login_user, "login user {$login_id} exists");
        $user = $this->userTable()->getValueModel()->query()->withoutGlobalScopes()->find($login_user->base_user_id);
        $this->assertInstanceOf(CustomValue::class, $user);
        return $user;
    }

    /**
     * Change a user the way the list tools do: inline edit, bulk edit and
     * "Set me as" all PUT the record with the screen key.
     *
     * @param array<string, mixed> $value
     * @return int status code
     */
    private function screenWrite(CustomValue $user, array $value): int
    {
        $table_name = SystemTableName::USER;
        $request = Request::create("/admin/webapi/data/{$table_name}/{$user->id}", 'PUT', [self::SCREEN_WRITE => 1, 'value' => $value]);
        $this->app->instance('request', $request);
        $result = (new ApiDataController($this->userTable(), $request))->dataUpdate($request, $table_name, $user->id);

        return $result instanceof Response ? $result->getStatusCode() : 200;
    }

    /**
     * One cell of the user list, as the inline editor reloads it. It ends
     * with GridInlineEditor::LOCKED_MARKER exactly when the grid's own cell
     * does: both ask GridInlineEditor::isFormEditableColumn(), and
     * grid_tools.js takes the pen off a cell that carries the marker.
     */
    private function cellHtml(CustomValue $user, string $column_name): string
    {
        $table_name = SystemTableName::USER;
        $request = Request::create("/admin/webapi/data/{$table_name}/cell/{$user->id}/{$column_name}", 'GET');
        $this->app->instance('request', $request);
        $result = (new ApiDataController($this->userTable(), $request))->dataCellHtml($request, $table_name, $user->id, $column_name);
        $this->assertIsArray($result, 'the cell is drawn');

        return strval($result['html'] ?? '');
    }

    public function testDataAdminCannotChangeTheAdministratorFromTheList(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $before = [$admin->getValue('user_name'), $admin->getValue('email')];

        $status = $this->screenWrite($admin, ['user_name' => 'renamed by user1', 'email' => 'renamed-by-user1@example.com']);

        $this->assertSame(403, $status, 'user1 may not change the administrator from the list');
        $after = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertSame($before, [$after->getValue('user_name'), $after->getValue('email')], 'name and e-mail address are unchanged');
    }

    public function testListDrawsNoPenOnTheAdministratorRowForADataAdmin(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertTrue(GridInlineEditor::isEditableGrid($this->userTable()), 'user1 gets the inline editor on the user list');

        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
        foreach (['user_name', 'email'] as $column_name) {
            $this->assertStringContainsString(GridInlineEditor::LOCKED_MARKER, $this->cellHtml($admin, $column_name), "no pen on the administrator's {$column_name}");
            $this->assertStringNotContainsString(GridInlineEditor::LOCKED_MARKER, $this->cellHtml($user2, $column_name), "the other rows keep the pen on {$column_name}");
        }
    }

    public function testDataAdminStillChangesOtherUsersFromTheList(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->assertSame(200, $this->screenWrite($user2, ['user_name' => 'renamed by user1']));
        $this->assertSame('renamed by user1', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('user_name'));
    }

    public function testAdministratorStillChangesTheAdministratorFromTheList(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->assertStringNotContainsString(GridInlineEditor::LOCKED_MARKER, $this->cellHtml($admin, 'user_name'), 'the administrator keeps the pen');
        $this->assertSame(200, $this->screenWrite($admin, ['user_name' => 'renamed by admin']));
        $this->assertSame('renamed by admin', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->getValue('user_name'));
    }
}
