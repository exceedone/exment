<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Services\RecordUrl;
use Exceedone\Exment\Services\ReplaceFormat\ReplaceFormatService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Opening a notification opens its record: marked read, then the record's
 * page - the plugin's screen when a plugin shows the table (RecordUrl), else
 * the data page. Only for a record the user may see, and only to an address
 * on this site. The notification itself stays at ?content=1.
 */
class NotifyOpenRecordTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        RecordUrl::reset();
    }

    protected function tearDown(): void
    {
        RecordUrl::reset();
        parent::tearDown();
    }

    public function testOpeningANotificationOpensTheRecordAndMarksItRead(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, $record);

        $this->get(admin_url("notify_navbar/{$notify->id}"))
            ->assertRedirect($record->getUrl());
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $notify->id, 'read_flg' => 1]);

        // the old link of the list does the same
        $this->get(admin_url("notify_navbar/rowdetail/{$notify->id}"))
            ->assertRedirect($record->getUrl());
    }

    public function testTheNotificationItselfStaysReachable(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, $record);

        // the list links to it (unread rows come first on the list)
        $this->get(admin_url('notify_navbar'))
            ->assertOk()
            ->assertSee("notify_navbar/{$notify->id}?content=1", false);

        $this->get(admin_url("notify_navbar/{$notify->id}") . '?content=1')
            ->assertOk()
            ->assertSee('claude open body');
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $notify->id, 'read_flg' => 1]);
    }

    public function testANotificationWithoutARecordShowsItself(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, null);

        $this->get(admin_url("notify_navbar/{$notify->id}"))
            ->assertOk()
            ->assertSee('claude open body');
    }

    public function testARecordTheUserMayNotSeeIsNotOpened(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2));
        $hidden = $this->hiddenRecord(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_USER2, $hidden);

        // a plugin would open it - but the user may not see the record
        RecordUrl::register($hidden->custom_table->table_name, function () {
            return '/admin/plugins/claude#secret';
        });

        $response = $this->get(admin_url("notify_navbar/{$notify->id}"));
        $response->assertOk()->assertSee('claude open body');
        $this->assertStringNotContainsString('#secret', $response->getContent());

        $this->get(admin_url("notify_navbar/rowdetail/{$notify->id}"))
            ->assertRedirect(admin_url("notify_navbar/{$notify->id}") . '?content=1');
    }

    public function testAnotherUsersNotificationIsNotFound(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, $record);

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->get(admin_url("notify_navbar/rowdetail/{$notify->id}"))
            ->assertNotFound();
        $this->assertDatabaseHas(SystemTableName::NOTIFY_NAVBAR, ['id' => $notify->id, 'read_flg' => 0]);
    }

    public function testAPluginsScreenIsOpenedWhenItShowsTheTable(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, $record);

        RecordUrl::register($record->custom_table->table_name, function (CustomValue $value) {
            return admin_url('plugins/claude_portal/CODE') . '#issue-' . $value->id;
        });

        $this->get(admin_url("notify_navbar/{$notify->id}"))
            ->assertRedirect(admin_url('plugins/claude_portal/CODE') . '#issue-' . $record->id);
    }

    public function testOnlyAddressesOnThisSiteAreUsed(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, $record);
        $root = rtrim(url('/'), '/');

        $refused = [
            'https://evil.example.com/admin',
            '//evil.example.com/admin',
            '/\\evil.example.com',
            'javascript:alert(1)',
            'http://' . parse_url($root, PHP_URL_HOST) . ':8443/admin',
            str_replace('://', '://user@', $root) . '/admin',
            "/admin\r\nLocation: https://evil.example.com",
            'admin/plugins/x',
        ];
        foreach ($refused as $url) {
            RecordUrl::reset();
            RecordUrl::register($record->custom_table->table_name, function () use ($url) {
                return $url;
            });
            $this->get(admin_url("notify_navbar/{$notify->id}"))
                ->assertRedirect($record->getUrl());
        }

        // a path is made full with this site's root
        $this->assertSame($root . '/admin/plugins/x#a', RecordUrl::sameSite('/admin/plugins/x#a'));
        $this->assertSame($root . '/admin/x', RecordUrl::sameSite($root . '/admin/x'));
    }

    public function testABrokenPluginFallsBackToTheDataPage(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $notify = $this->notification(TestDefine::TESTDATA_USER_LOGINID_ADMIN, $record);

        RecordUrl::register($record->custom_table->table_name, function () {
            throw new \RuntimeException('claude broken plugin');
        });

        $this->get(admin_url("notify_navbar/{$notify->id}"))
            ->assertRedirect($record->getUrl());
    }

    public function testTheMailLinkUsesThePluginsScreen(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $record = $this->record(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);

        $this->assertSame($record->getUrl(), ReplaceFormatService::replaceTextFromFormat('${value_url}', $record));

        RecordUrl::register($record->custom_table->table_name, function (CustomValue $value) {
            return '/admin/plugins/claude_portal/CODE#issue-' . $value->id;
        });
        $expected = rtrim(url('/'), '/') . '/admin/plugins/claude_portal/CODE#issue-' . $record->id;
        $this->assertSame($expected, ReplaceFormatService::replaceTextFromFormat('${value_url}', $record));

        // the link form too
        $link = ReplaceFormatService::replaceTextFromFormat('${value_url/link=1}', $record);
        $this->assertStringContainsString('href="' . $expected . '"', $link);

        // the other links of the record stay Exment's own
        $this->assertSame(admin_urls('data', $record->custom_table->table_name, $record->id), $record->getUrl());
        $this->assertSame(admin_urls('data', $record->custom_table->table_name, $record->id, 'edit'), $record->getUrl(['uri' => 'edit', 'record_url' => true]));
    }

    protected function record(string $table_name): CustomValue
    {
        $record = CustomTable::getEloquent($table_name)->getValueModel()->query()->first();
        $this->assertInstanceOf(CustomValue::class, $record);

        return $record;
    }

    /**
     * A record of the table that the login user may not see.
     */
    protected function hiddenRecord(string $table_name): CustomValue
    {
        $rows = CustomTable::getEloquent($table_name)->getValueModel()->newQueryWithoutScopes()->whereNull('deleted_at')->get();
        foreach ($rows as $row) {
            if ($row->enableAccess() !== true) {
                return $row;
            }
        }
        $this->fail('every record of ' . $table_name . ' is visible to the login user');
    }

    protected function notification(string $login_id, ?CustomValue $record): NotifyNavbar
    {
        $notify = new NotifyNavbar();
        $notify->notify_id = 1;
        $notify->target_user_id = LoginUser::findOrFail($login_id)->base_user_id;
        $notify->trigger_user_id = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN)->base_user_id;
        $notify->read_flg = false;
        $notify->parent_type = $record?->custom_table->table_name;
        $notify->parent_id = $record?->id;
        $notify->notify_subject = 'claude open ' . short_uuid();
        $notify->notify_body = 'claude open body';
        $notify->save();

        return $notify;
    }
}
