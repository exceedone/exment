<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;

/**
 * The settings of the safety check and of the full-text search (Meilisearch) on pages of their
 * own, not on the detail page of the system settings (#2157): who sees them, what they save,
 * the old links, and their menus.
 */
class SystemSettingPagesTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    /**
     * @param int|string $id the login user of the test data (TestDefine)
     */
    protected function loginAs($id): void
    {
        $this->be(LoginUser::findOrFail($id));
        System::clearRequestSession();
    }

    /**
     * @return array<string, mixed>
     */
    protected function safetyCheckInput(): array
    {
        return [
            'safety_check_auto_enabled' => '0',
            'safety_check_min_scale' => '50',
            'safety_check_cooldown_minutes' => '30',
            'safety_check_comment_window_minutes' => '40',
            'safety_check_max_bulletin_age_minutes' => '15',
            'safety_check_resend_throttle_minutes' => '3',
            'safety_check_answer_url_expire_days' => '4',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function meiliInput(): array
    {
        return [
            'meili_host' => 'http://meili-page-test.invalid:7700',
            'meili_index' => 'page_test',
            'meili_global_search' => '0',
            'meili_realtime_sync' => '0',
            'meili_batch_size' => '123',
            'meili_repair_enabled' => '0',
            'meili_repair_at' => '03:30',
            'meili_filter_mode' => 'manual',
        ];
    }

    public function testTheSystemAdministratorOpensThePages(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->get(admin_url('safety_check_setting'))->assertOk()
            ->assertSee('name="safety_check_min_scale"', false)
            ->assertSee('name="safety_check_answer_url_expire_days"', false);

        $this->get(admin_url('meili_setting'))->assertOk()
            ->assertSee('name="meili_host"', false)
            ->assertSee('name="meili_filter_mode"', false);
    }

    public function testAUserWithoutTheSystemPermissionIsRefused(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $before = System::safety_check_min_scale();
        $beforeHost = System::meili_host();

        // as ajax: the error page of a permission ends the process on a page request
        foreach (['safety_check_setting', 'meili_setting'] as $uri) {
            $page = $this->get(admin_url($uri), static::AJAX);
            $this->assertNotSame(200, $page->getStatusCode(), $uri);
        }
        $page->assertDontSee('name="meili_host"', false);

        $this->post(admin_url('safety_check_setting'), $this->safetyCheckInput(), static::AJAX);
        $this->post(admin_url('meili_setting'), $this->meiliInput(), static::AJAX);
        System::clearCache();
        $this->assertEquals($before, System::safety_check_min_scale(), 'not changed by a user without the permission');
        $this->assertEquals($beforeHost, System::meili_host(), 'not changed by a user without the permission');
    }

    public function testThePagesSaveTheSameSystemSettings(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->post(admin_url('safety_check_setting'), $this->safetyCheckInput())
            ->assertRedirect(admin_url('safety_check_setting'))->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertEquals(50, intval(System::safety_check_min_scale()));
        $this->assertEquals(30, intval(System::safety_check_cooldown_minutes()));
        $this->assertEquals(40, intval(System::safety_check_comment_window_minutes()));
        $this->assertEquals(15, intval(System::safety_check_max_bulletin_age_minutes()));
        $this->assertEquals(3, intval(System::safety_check_resend_throttle_minutes()));
        $this->assertEquals(4, intval(System::safety_check_answer_url_expire_days()));

        $this->post(admin_url('meili_setting'), $this->meiliInput())
            ->assertRedirect(admin_url('meili_setting'))->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertSame('http://meili-page-test.invalid:7700', System::meili_host());
        $this->assertSame('page_test', System::meili_index());
        $this->assertEquals(123, intval(System::meili_batch_size()));
        $this->assertSame('03:30', System::meili_repair_at());
        $this->assertSame('manual', System::meili_filter_mode());

        // the page shows what was saved
        $this->get(admin_url('meili_setting'))->assertOk()->assertSee('meili-page-test.invalid', false);

        // a wrong value is refused, and nothing is saved
        $this->post(admin_url('meili_setting'), array_merge($this->meiliInput(), ['meili_host' => 'http://other.invalid', 'meili_repair_at' => '25:00']))
            ->assertSessionHasErrors('meili_repair_at');
        System::clearCache();
        $this->assertSame('http://meili-page-test.invalid:7700', System::meili_host());
    }

    public function testSavingTheDetailPageKeepsTheMovedSettings(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        System::safety_check_min_scale(55);
        System::safety_check_answer_url_expire_days(5);
        System::meili_host('http://meili-kept.invalid:7700');
        System::meili_filter_mode('manual');
        System::clearCache();

        // what the detail page posts: its own settings only
        $input = [];
        foreach (System::get_system_values(['advanced', 'notify']) as $key => $value) {
            $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }
        $input['advanced'] = '1';
        $input['filter_search_type'] = $input['filter_search_type'] ?? 'forward';
        $this->assertArrayNotHasKey('meili_host', $input);
        $this->assertArrayNotHasKey('safety_check_min_scale', $input);

        $this->post(admin_url('system'), $input)->assertRedirect(admin_url('system') . '?advanced=1')->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertEquals(55, intval(System::safety_check_min_scale()));
        $this->assertEquals(5, intval(System::safety_check_answer_url_expire_days()));
        $this->assertSame('http://meili-kept.invalid:7700', System::meili_host());
        $this->assertSame('manual', System::meili_filter_mode());
    }

    public function testTheDetailPageNoLongerHasTheMovedSettings(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $page = $this->get(admin_url('system') . '?advanced=1')->assertOk();
        foreach (['meili_host', 'meili_key', 'meili_filter_mode', 'safety_check_auto_enabled', 'safety_check_min_scale', 'safety_check_answer_url_expire_days'] as $name) {
            $page->assertDontSee('name="' . $name . '"', false);
        }
        // Slack and LINE stay
        $page->assertSee('name="system_slack_user_column"', false);
        $page->assertSee('name="system_line_oa_basic_id"', false);
    }

    public function testTheOldLinksGoToTheNewPages(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->get(admin_urls_query('system', ['advanced' => '1', 'section' => 'safety_check']))->assertRedirect(admin_url('safety_check_setting'));
        $this->get(admin_urls_query('system', ['advanced' => '1', 'section' => 'meili']))->assertRedirect(admin_url('meili_setting'));
        // an unknown section: the detail page
        $this->get(admin_urls_query('system', ['advanced' => '1', 'section' => 'nothing']))->assertOk();

        // "?advanced=1#meili": the hash never reaches the server, the page sends it on
        $page = $this->get(admin_url('system') . '?advanced=1')->assertOk();
        $page->assertSee(strval(json_encode(admin_url('meili_setting'))), false);
        $page->assertSee(strval(json_encode(admin_url('safety_check_setting'))), false);
    }

    public function testTheSystemMenuHasThePages(): void
    {
        $this->loginAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $page = $this->get(admin_url('system'))->assertOk();
        $page->assertSee(admin_url('safety_check_setting'), false);
        $page->assertSee(admin_url('meili_setting'), false);
    }

    public function testAnUpdatedSiteGetsTheMenusAfterSystemSetting(): void
    {
        $admin = intval(DB::table('admin_menu')->where('menu_type', 'parent_node')->where('menu_name', 'admin')->value('id'));
        $this->assertGreaterThan(0, $admin);
        DB::table('admin_menu')->where('menu_type', 'system')->whereIn('menu_name', ['safety_check_setting', 'meili_setting'])->delete();

        $migration = require base_path('vendor/exceedone/exment/database/migrations/2026_12_05_000000_add_safety_check_and_meili_setting_menus.php');
        $migration->up();

        $children = DB::table('admin_menu')->where('parent_id', $admin)->orderBy('order')->orderBy('id')->pluck('menu_name')->map(function ($name) {
            return strval($name);
        })->all();
        $system = array_search('system', $children, true);
        $this->assertNotFalse($system);
        $this->assertSame(['system', 'safety_check_setting', 'meili_setting'], array_slice($children, intval($system), 3));
        $this->assertSame('safety_check_setting', DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'safety_check_setting')->value('uri'));

        // again: nothing more
        $migration->up();
        $this->assertSame(1, DB::table('admin_menu')->where('menu_type', 'system')->where('menu_name', 'meili_setting')->count());
    }
}
