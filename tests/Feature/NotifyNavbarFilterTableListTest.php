<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Notification list (header bell icon -> "notify_navbar"): "target table" filter options.
 *
 * The select must list the tables that actually appear in the login user's own
 * notifications (option value = table_name as stored in parent_type, label = table_view_name),
 * no matter which table permissions the login user has.
 *
 * Before the fix the select listed the tables the login user had "custom_table" permission on
 * (CustomTable::filterList()), so a normal user saw an empty list, and the option value was
 * table_view_name, so choosing an option never matched any notification row.
 *
 * This test creates its own users / notifications and rolls everything back,
 * so it does not depend on `exment:inittest` data.
 */
class NotifyNavbarFilterTableListTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        if (!System::permission_available()) {
            $this->markTestSkipped('permission is not available in this environment.');
        }

        $this->loginAs(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // ------------------------------------------------------------------ //
    //  Who sees what                                                      //
    // ------------------------------------------------------------------ //

    /**
     * Normal user without any table permission (no role group at all):
     * the filter must list the tables of the user's own notifications.
     * (was: empty list, because only "custom_table"-permitted tables were listed)
     */
    public function testUserWithoutTablePermissionSeesTablesOfOwnNotifications(): void
    {
        $loginUser = $this->createLoginUser();
        $this->createNotify($loginUser, SystemTableName::USER);
        $this->createNotify($loginUser, SystemTableName::ORGANIZATION);

        $this->loginAs($loginUser);
        $this->assertFalse(\Exment::user()->hasPermission(Permission::CUSTOM_TABLE), 'precondition: user must not have custom_table permission');
        $this->assertFalse(CustomTable::getEloquent(SystemTableName::USER)->hasPermission(Permission::CUSTOM_TABLE), 'precondition: user must not have table permission');

        $this->assertSame(
            $this->expectedOptions([SystemTableName::USER, SystemTableName::ORGANIZATION]),
            $this->getFilterOptions()
        );
    }

    /**
     * Administrator: the filter lists the tables of the administrator's own notifications,
     * not every table the administrator can manage.
     */
    public function testAdminSeesTablesOfOwnNotificationsOnly(): void
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertTrue($admin->hasPermission(Permission::CUSTOM_TABLE), 'precondition: admin must have custom_table permission');

        $this->createNotify($admin, SystemTableName::USER);
        $this->createNotify($admin, SystemTableName::ORGANIZATION);

        $options = $this->getFilterOptions();
        $expected = $this->expectedOptions([SystemTableName::USER, SystemTableName::ORGANIZATION]);
        foreach ($expected as $table_name => $table_view_name) {
            $this->assertArrayHasKey($table_name, $options);
            $this->assertSame($table_view_name, $options[$table_name]);
        }

        // every listed table must come from one of the admin's notifications
        // (the environment may already contain notifications for the admin, so allow those too)
        $existing = $this->parentTypesOf($admin);
        foreach (array_keys($options) as $table_name) {
            $this->assertContains($table_name, $existing, "table '{$table_name}' is listed but the admin has no notification of it");
        }

        // a table the admin can manage but has no notification of must not be listed
        $absent = collect([SystemTableName::MAIL_TEMPLATE, SystemTableName::DOCUMENT, SystemTableName::COMMENT])
            ->first(fn ($table_name) => !in_array($table_name, $existing, true));
        $this->assertNotNull($absent, 'precondition: need a table without notification');
        $this->assertArrayNotHasKey($absent, $options);
    }

    /**
     * Notifications addressed to other users must not contribute options.
     */
    public function testOtherUsersNotificationsAreNotListed(): void
    {
        $loginUser = $this->createLoginUser();
        $otherUser = $this->createLoginUser();
        $this->createNotify($loginUser, SystemTableName::ORGANIZATION);
        $this->createNotify($otherUser, SystemTableName::USER);

        $this->loginAs($loginUser);
        $this->assertSame($this->expectedOptions([SystemTableName::ORGANIZATION]), $this->getFilterOptions());

        $this->loginAs($otherUser);
        $this->assertSame($this->expectedOptions([SystemTableName::USER]), $this->getFilterOptions());
    }

    /**
     * User without any notification: no option at all (only the placeholder), page still renders.
     */
    public function testNoNotificationGivesNoOption(): void
    {
        $loginUser = $this->createLoginUser();

        $this->loginAs($loginUser);
        $this->assertSame([], $this->getFilterOptions());
    }

    // ------------------------------------------------------------------ //
    //  Data edge cases                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Notification without target table (e.g. sent via API): ignored for the options,
     * no empty option is added, and the notification itself is still listed.
     */
    public function testNotificationWithoutTargetTableIsIgnored(): void
    {
        $loginUser = $this->createLoginUser();
        $noTable = $this->createNotify($loginUser, null);
        $this->createNotify($loginUser, SystemTableName::USER);

        $this->loginAs($loginUser);
        $this->assertSame($this->expectedOptions([SystemTableName::USER]), $this->getFilterOptions());
        $this->assertContains($noTable->notify_subject, $this->getListedSubjects());
    }

    /**
     * Notification whose target table no longer exists: ignored for the options
     * (no crash), and the notification itself is still listed.
     */
    public function testNotificationOfMissingTableIsIgnored(): void
    {
        $loginUser = $this->createLoginUser();
        $missing = $this->createNotify($loginUser, 'nnfilter_missing_' . short_uuid());
        $this->createNotify($loginUser, SystemTableName::USER);

        $this->loginAs($loginUser);
        $this->assertSame($this->expectedOptions([SystemTableName::USER]), $this->getFilterOptions());
        $this->assertContains($missing->notify_subject, $this->getListedSubjects());
    }

    /**
     * Several notifications of the same table: the table is listed once.
     */
    public function testSameTableIsListedOnce(): void
    {
        $loginUser = $this->createLoginUser();
        $this->createNotify($loginUser, SystemTableName::USER);
        $this->createNotify($loginUser, SystemTableName::USER);
        $this->createNotify($loginUser, SystemTableName::USER);

        $this->loginAs($loginUser);
        $this->assertSame([[SystemTableName::USER, $this->viewName(SystemTableName::USER)]], $this->getFilterOptionList());
    }

    /**
     * Hidden table (showlist_flg = false): still listed when the user has a notification of it,
     * because the notification row itself is listed and shows that table name.
     */
    public function testHiddenTableIsListedWhenUserHasNotification(): void
    {
        $hidden = CustomTable::getEloquent(SystemTableName::DOCUMENT);
        if (boolval($hidden->showlist_flg)) {
            $hidden->showlist_flg = false;
            $hidden->save();
            System::clearCache();
        }
        $this->assertFalse(boolval(CustomTable::getEloquent(SystemTableName::DOCUMENT)->showlist_flg), 'precondition: document table must be hidden');

        $loginUser = $this->createLoginUser();
        $this->createNotify($loginUser, SystemTableName::DOCUMENT);

        $this->loginAs($loginUser);
        $this->assertSame($this->expectedOptions([SystemTableName::DOCUMENT]), $this->getFilterOptions());
    }

    // ------------------------------------------------------------------ //
    //  Filtering with the option                                          //
    // ------------------------------------------------------------------ //

    /**
     * Choosing an option must actually filter the list: the option value has to be
     * the value stored in parent_type (table_name), not table_view_name.
     */
    public function testSelectingOptionFiltersList(): void
    {
        $loginUser = $this->createLoginUser();
        $userNotify = $this->createNotify($loginUser, SystemTableName::USER);
        $orgNotify = $this->createNotify($loginUser, SystemTableName::ORGANIZATION);

        $this->loginAs($loginUser);

        // both listed without filter
        $subjects = $this->getListedSubjects();
        $this->assertContains($userNotify->notify_subject, $subjects);
        $this->assertContains($orgNotify->notify_subject, $subjects);

        // filter with the value rendered in the select
        $options = $this->getFilterOptions();
        $value = array_search($this->viewName(SystemTableName::ORGANIZATION), $options, true);
        $this->assertNotFalse($value, 'organization option must be rendered');

        $subjects = $this->getListedSubjects(['parent_type' => $value]);
        $this->assertContains($orgNotify->notify_subject, $subjects, 'notification of the chosen table must be listed');
        $this->assertNotContains($userNotify->notify_subject, $subjects, 'notification of another table must be filtered out');

        // the chosen option is shown as selected
        $this->assertSame($value, $this->getSelectedFilterValue(['parent_type' => $value]));
    }

    /**
     * The filter value is compared with parent_type as stored (table_name):
     * filtering with a table_view_name matches nothing.
     */
    public function testFilterValueIsTableName(): void
    {
        $loginUser = $this->createLoginUser();
        $orgNotify = $this->createNotify($loginUser, SystemTableName::ORGANIZATION);

        $this->loginAs($loginUser);

        $this->assertContains($orgNotify->notify_subject, $this->getListedSubjects(['parent_type' => SystemTableName::ORGANIZATION]));
        $this->assertNotContains($orgNotify->notify_subject, $this->getListedSubjects(['parent_type' => $this->viewName(SystemTableName::ORGANIZATION)]));
    }

    // ------------------------------------------------------------------ //
    //  Model method (used by the filter)                                  //
    // ------------------------------------------------------------------ //

    /**
     * NotifyNavbar::getTargetTableOptions(): [table_name => table_view_name] of the login user's notifications.
     */
    public function testGetTargetTableOptions(): void
    {
        $loginUser = $this->createLoginUser();
        $otherUser = $this->createLoginUser();
        $this->createNotify($loginUser, SystemTableName::USER);
        $this->createNotify($loginUser, SystemTableName::USER);
        $this->createNotify($loginUser, SystemTableName::ORGANIZATION);
        $this->createNotify($loginUser, null);
        $this->createNotify($loginUser, 'nnfilter_missing_' . short_uuid());
        $this->createNotify($otherUser, SystemTableName::MAIL_TEMPLATE);

        $this->loginAs($loginUser);
        $this->assertSame(
            $this->expectedOptions([SystemTableName::USER, SystemTableName::ORGANIZATION]),
            $this->sortByKey(NotifyNavbar::getTargetTableOptions())
        );

        $this->loginAs($otherUser);
        $this->assertSame($this->expectedOptions([SystemTableName::MAIL_TEMPLATE]), $this->sortByKey(NotifyNavbar::getTargetTableOptions()));
    }

    /**
     * Not logged in: nothing is listed (and no error).
     */
    public function testGetTargetTableOptionsWithoutLoginUser(): void
    {
        $loginUser = $this->createLoginUser();
        $this->createNotify($loginUser, SystemTableName::USER);

        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->assertNull(\Exment::getUserId(), 'precondition: no login user');

        $this->assertSame([], NotifyNavbar::getTargetTableOptions());
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    protected function loginAs(LoginUser $loginUser): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->be($loginUser);
    }

    /**
     * Create a user (custom value) and its login user. No role group is assigned.
     */
    protected function createLoginUser(): LoginUser
    {
        $code = 'nnfilter_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code,
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        $loginUser = new LoginUser();
        $loginUser->base_user_id = $user->id;
        $loginUser->password = 'nnfilter_password';
        $loginUser->save();

        return $loginUser;
    }

    /**
     * Create a notification addressed to $target. $parent_type is the table_name (or null).
     */
    protected function createNotify(LoginUser $target, ?string $parent_type, ?int $parent_id = null): NotifyNavbar
    {
        $notify = new NotifyNavbar();
        $notify->notify_id = -1;
        $notify->parent_type = $parent_type;
        $notify->parent_id = $parent_id;
        $notify->target_user_id = $target->base_user_id;
        $notify->trigger_user_id = TestDefine::TESTDATA_USER_LOGINID_ADMIN;
        $notify->read_flg = false;
        $notify->notify_subject = 'nnfilter_subject_' . short_uuid();
        $notify->notify_body = 'nnfilter body';
        $notify->save();

        return $notify;
    }

    /**
     * Distinct parent_type of all notifications addressed to $loginUser (test-side query).
     *
     * @return string[]
     */
    protected function parentTypesOf(LoginUser $loginUser): array
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', $loginUser->base_user_id)
            ->whereNotNull('parent_type')
            ->pluck('parent_type')
            ->unique()
            ->values()
            ->all();
    }

    protected function viewName(string $table_name): string
    {
        return CustomTable::getEloquent($table_name)->table_view_name;
    }

    /**
     * @param string[] $table_names
     * @return array<string, string> [table_name => table_view_name], sorted by key
     */
    protected function expectedOptions(array $table_names): array
    {
        $expected = [];
        foreach ($table_names as $table_name) {
            $expected[$table_name] = $this->viewName($table_name);
        }

        return $this->sortByKey($expected);
    }

    /**
     * @param array<string, string> $options
     * @return array<string, string>
     */
    protected function sortByKey(array $options): array
    {
        ksort($options);

        return $options;
    }

    /**
     * GET the notification list and return the rendered options of the "target table" filter
     * as [value => label], sorted by key. The placeholder option (empty value) is skipped.
     *
     * @param array<string, mixed> $query
     * @return array<string, string>
     */
    protected function getFilterOptions(array $query = []): array
    {
        $options = [];
        foreach ($this->getFilterOptionList($query) as [$value, $label]) {
            $options[$value] = $label;
        }

        return $this->sortByKey($options);
    }

    /**
     * Same as getFilterOptions() but keeps duplicates and order: [[value, label], ...].
     *
     * @param array<string, mixed> $query
     * @return array<int, array{0: string, 1: string}>
     */
    protected function getFilterOptionList(array $query = []): array
    {
        $xpath = $this->getPageXPath($query);

        $selects = $xpath->query('//select[@name="parent_type"]');
        $this->assertNotFalse($selects);
        $this->assertSame(1, $selects->length, 'target table filter select must be rendered exactly once');
        $select = $selects->item(0);
        if (!$select instanceof \DOMElement) {
            $this->fail('target table filter select must be an element');
        }

        $result = [];
        $options = $xpath->query('option', $select);
        $this->assertNotFalse($options);
        foreach ($options as $option) {
            if (!$option instanceof \DOMElement) {
                continue;
            }
            $value = $option->getAttribute('value');
            if ($value === '') {
                continue;
            }
            $result[] = [$value, trim($option->textContent)];
        }

        return $result;
    }

    /**
     * Value of the option rendered as selected in the "target table" filter (null if none).
     *
     * @param array<string, mixed> $query
     */
    protected function getSelectedFilterValue(array $query = []): ?string
    {
        $xpath = $this->getPageXPath($query);
        $selected = $xpath->query('//select[@name="parent_type"]/option[@selected]');
        $this->assertNotFalse($selected);
        if ($selected->length === 0) {
            return null;
        }
        $this->assertSame(1, $selected->length, 'only one option can be selected');
        $option = $selected->item(0);
        if (!$option instanceof \DOMElement) {
            $this->fail('selected option must be an element');
        }

        return $option->getAttribute('value');
    }

    /**
     * GET the notification list and return the notify_subject cell text of every listed row.
     *
     * @param array<string, mixed> $query
     * @return string[]
     */
    protected function getListedSubjects(array $query = []): array
    {
        $xpath = $this->getPageXPath(array_merge(['per_page' => 100], $query));

        $headers = $xpath->query('//table//thead/tr/th');
        $this->assertNotFalse($headers);
        $columnIndex = null;
        foreach ($headers as $index => $th) {
            if ($th instanceof \DOMElement && mb_strpos(trim($th->textContent), exmtrans('notify_navbar.notify_subject')) !== false) {
                $columnIndex = $index;
                break;
            }
        }
        $this->assertNotNull($columnIndex, 'notify_subject column should exist on the list');

        $result = [];
        $rows = $xpath->query('//table//tbody/tr');
        $this->assertNotFalse($rows);
        foreach ($rows as $row) {
            if (!$row instanceof \DOMElement) {
                continue;
            }
            $cells = $xpath->query('td', $row);
            $this->assertNotFalse($cells);
            $cell = $cells->item($columnIndex);
            if (!$cell instanceof \DOMElement) {
                continue; // e.g. "no data" row
            }
            $result[] = trim($cell->textContent);
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $query
     */
    protected function getPageXPath(array $query = []): \DOMXPath
    {
        $response = $this->get(admin_urls_query('notify_navbar', $query));
        $response->assertStatus(200);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $response->getContent());
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }
}
