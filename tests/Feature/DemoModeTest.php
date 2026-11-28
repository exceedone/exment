<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ApiClientType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Jobs\SlackSendJob;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\ApiClient;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\PublicForm;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\Mail\MailChannel;
use Exceedone\Exment\Notifications\Navbar\NavbarChannel;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * Demo mode (config exment.demo_mode): a public demo site publishes the login of its
 * administrator. The operations that run code on the server, change the server, send
 * anything outside or show secrets are refused, even for the administrator. Normal data
 * work stays, and without demo mode everything is as before.
 *
 * A refused page request goes back with the message (session error "demo_mode"); an ajax
 * or API request gets 403 json with the code ErrorCode::DEMO_MODE.
 */
class DemoModeTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    private const ADMIN_PASSWORD = 'adminadmin';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        config(['exment.demo_mode' => true]);
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    // helpers ----------------------------------------------------------------------

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertRefusedPage(TestResponse $response): void
    {
        $response->assertRedirect();
        $response->assertSessionHasErrors('demo_mode');
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function assertRefusedJson(TestResponse $response): void
    {
        $response->assertStatus(403);
        $response->assertJson([
            'result' => false,
            'code' => ErrorCode::DEMO_MODE()->getValue(),
            'message' => DemoMode::message(),
        ]);
    }

    /**
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     */
    private function jsonText(TestResponse $response): string
    {
        return (string) json_encode($response->json(), JSON_UNESCAPED_UNICODE);
    }

    private function passwordOf(string $login_id): string
    {
        return (string) LoginUser::findOrFail($login_id)->password;
    }

    private function userOf(string $login_id): CustomValue
    {
        System::clearRequestSession();
        $base_user_id = LoginUser::findOrFail($login_id)->base_user_id;
        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel()->query()->withoutGlobalScopes()->find($base_user_id);
        $this->assertInstanceOf(CustomValue::class, $user);

        return $user;
    }

    // plugins -------------------------------------------------------------------------

    public function testPluginUploadIsRefused(): void
    {
        $count = Plugin::count();

        $this->assertRefusedPage($this->post(admin_url('plugin'), [
            'fileUpload' => UploadedFile::fake()->create('plugin.zip', 1, 'application/zip'),
        ]));
        $this->assertSame($count, Plugin::count());
    }

    public function testPluginSettingsDeleteAndCodeEditingAreRefused(): void
    {
        $this->assertRefusedPage($this->get(admin_url('plugin/1/edit')));
        $this->assertRefusedPage($this->put(admin_url('plugin/1'), ['active_flg' => 0]));
        $this->assertRefusedJson($this->delete(admin_url('plugin/1'), [], self::AJAX));
        $this->assertRefusedPage($this->get(admin_url('plugin/edit_code/1')));
        $this->assertRefusedPage($this->post(admin_url('plugin/edit_code/1'), ['nodepath' => 'Plugin.php', 'original' => '<?php']));
    }

    public function testThePluginListHasNoUploadForm(): void
    {
        $this->get(admin_url('plugin'), self::AJAX)
            ->assertOk()
            ->assertDontSee('fileUpload', false);
    }

    // backup ----------------------------------------------------------------------------

    public function testBackupIsRefused(): void
    {
        $this->assertRefusedJson($this->post(admin_url('backup/save'), [], self::AJAX));
        $this->assertRefusedJson($this->post(admin_url('backup/restore'), ['file' => '20240101000000'], self::AJAX));
        $this->assertRefusedJson($this->post(admin_url('backup/import'), ['restore_zipfile' => '20240101000000'], self::AJAX));
        $this->assertRefusedJson($this->delete(admin_url('backup/delete'), ['files' => '20240101000000'], self::AJAX));
        $this->assertRefusedPage($this->get(admin_url('backup/download/20240101000000')));
        $this->assertRefusedPage($this->get(admin_url('backup')));
        $this->assertFalse($this->app->isDownForMaintenance());
    }

    // templates ---------------------------------------------------------------------------

    public function testTemplateUploadAndDeleteAreRefused(): void
    {
        $this->assertRefusedPage($this->post(admin_url('template/import'), [
            'upload_template' => UploadedFile::fake()->create('template.zip', 1, 'application/zip'),
        ]));
        // the delete button of the template list is a webapi
        $this->assertRefusedJson($this->delete(admin_urls('webapi', 'template', 'delete'), ['template' => 'demo'], self::AJAX));
    }

    // system settings -----------------------------------------------------------------------

    /**
     * What the detail page of the system settings posts: the current values, and the changes.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function advancedSystemInput(array $changes): array
    {
        $input = [];
        foreach (System::get_system_values(['advanced', 'notify']) as $key => $value) {
            $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }
        $input['advanced'] = '1';
        $input['filter_search_type'] = $input['filter_search_type'] ?? 'forward';

        return array_merge($input, $changes);
    }

    private function otherPagerCount(): int
    {
        return intval(System::grid_pager_count()) === 50 ? 100 : 50;
    }

    public function testMailServerSettingsAreNotSavedButDisplaySettingsAre(): void
    {
        $host = System::system_mail_host();
        $pager = $this->otherPagerCount();

        $this->post(admin_url('system'), $this->advancedSystemInput([
            'system_mail_host' => 'smtp.demo-mode.example.com',
            'system_mail_password' => 'demo-mode-password',
            'web_ip_filters' => '192.0.2.1',
            'grid_pager_count' => $pager,
        ]))->assertRedirect(admin_url('system') . '?advanced=1')->assertSessionHasNoErrors();

        System::clearCache();
        $this->assertSame($host, System::system_mail_host(), 'the mail server is kept');
        $this->assertNotSame('demo-mode-password', System::system_mail_password());
        $this->assertNotSame('192.0.2.1', System::web_ip_filters(), 'the IP filter is kept');
        $this->assertSame($pager, intval(System::grid_pager_count()), 'a display setting is saved');
    }

    public function testTheTestMailIsRefused(): void
    {
        Notification::fake();

        $this->assertRefusedJson($this->post(admin_url('system/send_testmail'), ['test_mail_to' => 'demo-mode@example.com'], self::AJAX));
        Notification::assertNothingSent();
    }

    public function testTheSystemUpdateIsNotThere(): void
    {
        // the update from the screen is removed: nothing to refuse
        $this->post(admin_url('system/call_update'), [], self::AJAX)->assertNotFound();
        $this->assertFalse($this->app->isDownForMaintenance());
    }

    public function testEveryMailGoesToTheLog(): void
    {
        config(['mail.default' => 'smtp']);
        Initialize::initializeConfig();

        $this->assertSame('log', config('mail.default'));
    }

    public function testOnlyMailAndNavbarNotificationsAreSent(): void
    {
        $this->assertTrue(DemoMode::allowsNotificationChannel(MailChannel::class));
        $this->assertTrue(DemoMode::allowsNotificationChannel(NavbarChannel::class));
        $this->assertFalse(DemoMode::allowsNotificationChannel('slack'));

        // the listener cancels a Slack message: the notification sender stops on false
        $event = new NotificationSending(new AnonymousNotifiable(), new SlackSendJob('name', null, 'content'), 'slack');
        $this->assertFalse(app('events')->until($event));
        $event = new NotificationSending(new AnonymousNotifiable(), new MailSendJob(), MailChannel::class);
        $this->assertNull(app('events')->until($event));
    }

    // login settings -------------------------------------------------------------------------

    public function testLoginSettingsAreRefused(): void
    {
        $count = LoginSetting::count();

        $this->assertRefusedPage($this->post(admin_url('login_setting'), [
            'login_view_name' => 'Demo mode oauth',
            'login_type' => LoginType::OAUTH,
            'options' => [
                'oauth_provider_type' => 'google',
                'oauth_client_id' => 'demo-mode-client',
                'oauth_client_secret' => 'demo-mode-secret',
            ],
        ]));
        $this->assertSame($count, LoginSetting::count());

        $this->assertRefusedPage($this->get(admin_url('login_setting/create')));
        $this->assertRefusedPage($this->post(admin_url('login_setting/postglobal'), ['password_expiration_days' => 1]));
        $this->assertRefusedPage($this->post(admin_url('login_setting/2factor'), ['login_use_2factor' => 1]));
        $this->assertRefusedJson($this->post(admin_url('login_setting/1/activate'), [], self::AJAX));
    }

    // API settings ----------------------------------------------------------------------------

    public function testApiKeyCreationIsRefused(): void
    {
        $count = ApiClient::count();

        $this->assertRefusedPage($this->post(admin_url('api_setting'), [
            'client_type' => ApiClientType::API_KEY,
            'name' => 'Demo mode api key',
        ]));
        $this->assertSame($count, ApiClient::count());

        // the edit page shows the client secret
        $this->assertRefusedPage($this->get(admin_url('api_setting/1/edit')));
    }

    // passwords and login IDs -------------------------------------------------------------------

    public function testPasswordChangeIsRefused(): void
    {
        $before = $this->passwordOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $this->assertRefusedPage($this->post(admin_url('auth/change'), [
            'current_password' => self::ADMIN_PASSWORD,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ]));

        // the user's own setting page
        $this->assertRefusedPage($this->put(admin_url('auth/setting'), [
            'current_password' => self::ADMIN_PASSWORD,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ]));

        $this->assertSame($before, $this->passwordOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        $this->assertTrue(\Hash::check(self::ADMIN_PASSWORD, $this->passwordOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN)));
    }

    public function testThePasswordOfAnotherUserIsNotReset(): void
    {
        $before = $this->passwordOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->assertRefusedPage($this->put(admin_url("loginuser/{$user2->id}"), [
            'use_loginuser' => '1',
            'reset_password' => '1',
            'create_password_auto' => '0',
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
            'password_reset_flg' => '0',
            'send_password' => '0',
        ]));
        $this->assertRefusedJson($this->post(admin_url('loginuser/import'), [], self::AJAX));

        // the password reset of the login page
        $this->assertRefusedPage($this->post(admin_url('auth/forget'), ['email' => 'user2@demo.com']));
        $this->assertRefusedPage($this->post(admin_url('auth/reset/demo-mode-token'), [
            'email' => 'user2@demo.com',
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ]));

        $this->assertSame($before, $this->passwordOf(TestDefine::TESTDATA_USER_LOGINID_USER2));
    }

    public function testTheLoginIdOfAUserWhoCanLogInStays(): void
    {
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $email = $user2->getValue('email');

        // the user code can never be changed; demo mode also keeps the e-mail address, a login ID too
        $response = $this->put(admin_urls('webapi', 'data', SystemTableName::USER, $user2->id), [
            'value' => ['email' => 'demomode-renamed@example.com'],
        ])->assertStatus(400);
        $this->assertStringContainsString(strval(exmtrans('demo_mode.message_login_id')), $this->jsonText($response));
        $this->assertSame($email, $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('email'));

        // the other values of the user can be changed
        $this->put(admin_urls('webapi', 'data', SystemTableName::USER, $user2->id), [
            'value' => ['user_name' => 'demo mode renamed'],
        ])->assertOk();
        $this->assertSame('demo mode renamed', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('user_name'));
    }

    public function testAUserWhoCanLogInIsNotDeleted(): void
    {
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $response = $this->delete(admin_urls('webapi', 'data', SystemTableName::USER, $user2->id))->assertStatus(400);
        $this->assertStringContainsString(strval(exmtrans('demo_mode.message_user_delete')), $this->jsonText($response));
        $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
    }

    // normal data work ------------------------------------------------------------------------------

    public function testDataCanBeCreatedUpdatedAndDeleted(): void
    {
        $table = TestDefine::TESTDATA_TABLE_NAME_EDIT;

        $response = $this->post(admin_urls('webapi', 'data', $table), [
            'value' => ['text' => 'demo mode created', 'user' => 3],
        ])->assertStatus(201);
        $id = $response->json('id');
        $this->assertNotNull($id);

        $this->put(admin_urls('webapi', 'data', $table, $id), [
            'value' => ['text' => 'demo mode updated'],
        ])->assertOk();
        System::clearRequestSession();
        $value = CustomTable::getEloquent($table)->getValueModel($id);
        $this->assertInstanceOf(CustomValue::class, $value);
        $this->assertSame('demo mode updated', $value->getValue('text'));

        $this->delete(admin_urls('webapi', 'data', $table, $id))->assertStatus(204);
        System::clearRequestSession();
        $this->assertNull(CustomTable::getEloquent($table)->getValueModel($id));
    }

    public function testEveryAdminPageShowsTheBanner(): void
    {
        $this->get(admin_url(''))
            ->assertOk()
            ->assertSee(DemoMode::bannerText());
    }

    // operation log ---------------------------------------------------------------------------------

    public function testTheOperationLogIsRefused(): void
    {
        $this->assertRefusedPage($this->get(admin_url('auth/logs')));
        $this->assertRefusedPage($this->get(admin_url('auth/logs/1')));
        $this->assertRefusedJson($this->delete(admin_url('auth/logs/1'), [], self::AJAX));
        $this->assertRefusedPage($this->post(admin_url('auth/logs/setting'), ['logging_toggle_available' => 0]));
        // the log API
        $this->assertRefusedJson($this->get(admin_urls('webapi', 'log')));
        $this->assertRefusedJson($this->get(admin_urls('webapi', 'log', 1)));
    }

    public function testTheMenuHasNoItemOfARefusedPage(): void
    {
        $uris = collect((new Menu())->allNodes())->pluck('uri')->all();

        $this->assertNotContains('auth/logs', $uris);
        $this->assertNotContains('backup', $uris);
    }

    // public form scripts ---------------------------------------------------------------------------

    private function publicForm(): PublicForm
    {
        $public_form = PublicForm::query()->orderBy('id')->first();
        $this->assertInstanceOf(PublicForm::class, $public_form);

        return $public_form;
    }

    private function savedPublicForm(PublicForm $public_form): PublicForm
    {
        $saved = PublicForm::query()->find($public_form->id);
        $this->assertInstanceOf(PublicForm::class, $saved);

        return $saved;
    }

    public function testPublicFormScriptsKeepTheirOldValue(): void
    {
        // the owner's script, saved without demo mode
        config(['exment.demo_mode' => false]);
        $public_form = $this->publicForm();
        $public_form->setOption('custom_js', 'console.log("owner");');
        $public_form->save();

        // a visitor's script and style are not saved, other settings are
        config(['exment.demo_mode' => true]);
        $public_form = $this->savedPublicForm($public_form);
        $public_form->setOption('custom_js', 'alert("visitor");');
        $public_form->setOption('custom_css', 'body { display: none; }');
        $public_form->setOption('complete_title', 'demo mode complete');
        $public_form->save();

        $saved = $this->savedPublicForm($public_form);
        $this->assertSame('console.log("owner");', $saved->getOption('custom_js'));
        $this->assertNull($saved->getOption('custom_css'));
        $this->assertSame('demo mode complete', $saved->getOption('complete_title'));
    }

    public function testThePublicFormSettingPageHasNoScriptInput(): void
    {
        $public_form = $this->publicForm();
        $table_name = $public_form->custom_form->custom_table->table_name;

        $this->get(admin_urls('formpublic', $table_name, $public_form->id, 'edit'), self::AJAX)
            ->assertOk()
            ->assertDontSee('css_js_setting[custom_js]', false)
            ->assertDontSee('css_js_setting[custom_css]', false)
            ->assertSee(strval(exmtrans('demo_mode.message_public_form_script')));
    }

    // system administrators ---------------------------------------------------------------------------

    /**
     * What the basic page of the system settings posts: the current values, and the changes.
     *
     * @param array<string, mixed> $changes
     * @return array<string, mixed>
     */
    private function basicSystemInput(array $changes): array
    {
        $input = [];
        foreach (System::get_system_values(['initialize']) as $key => $value) {
            if (in_array($key, ['site_logo', 'site_logo_mini', 'site_favicon'], true)) {
                continue;
            }
            $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }

        return array_merge($input, $changes);
    }

    public function testTheSystemAdministratorsStay(): void
    {
        $admins = System::system_admin_users();
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->post(admin_url('system'), $this->basicSystemInput([
            'site_name' => 'Demo mode site',
            'system_admin_users' => [strval($user2->id)],
        ]))->assertRedirect(admin_url('system'))->assertSessionHasNoErrors();

        System::clearCache();
        $this->assertEquals($admins, System::system_admin_users(), 'the system administrators are kept');
        $this->assertSame('Demo mode site', System::site_name(), 'the other basic settings are saved');
    }

    public function testTheAdministratorIsNotDeleted(): void
    {
        $admin = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        $status = $this->delete(admin_urls('webapi', 'data', SystemTableName::USER, $admin->id))->getStatusCode();
        $this->assertGreaterThanOrEqual(400, $status);
        $this->userOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertNotNull(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // without demo mode ------------------------------------------------------------------------------

    public function testWithoutDemoModeNothingIsRefused(): void
    {
        config(['exment.demo_mode' => false]);

        // the banner is not shown
        $this->get(admin_url(''))
            ->assertOk()
            ->assertDontSee(DemoMode::bannerText());

        // the plugin upload answers as before: no file, no plugin
        $this->post(admin_url('plugin'))
            ->assertRedirect()
            ->assertSessionHas('errorMess')
            ->assertSessionDoesntHaveErrors('demo_mode');

        // an API client is created
        $this->post(admin_url('api_setting'), [
            'client_type' => ApiClientType::CLIENT_CREDENTIALS,
            'name' => 'Demo mode off api client',
            'redirect' => 'http://localhost/demo-mode-off',
        ])->assertRedirect();
        $this->assertNotNull(ApiClient::where('name', 'Demo mode off api client')->first());

        // the e-mail address of a user can be changed
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->put(admin_urls('webapi', 'data', SystemTableName::USER, $user2->id), [
            'value' => ['email' => 'demomode-off@example.com'],
        ])->assertOk();
        $this->assertSame('demomode-off@example.com', $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2)->getValue('email'));

        // mail settings are saved
        $this->post(admin_url('system'), $this->advancedSystemInput([
            'system_mail_host' => 'smtp.demo-mode-off.example.com',
        ]))->assertRedirect(admin_url('system') . '?advanced=1')->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertSame('smtp.demo-mode-off.example.com', System::system_mail_host());

        // the mail driver is not changed
        config(['mail.default' => 'array']);
        Initialize::initializeConfig(false);
        $this->assertSame('array', config('mail.default'));
        $this->assertTrue(DemoMode::allowsNotificationChannel('slack'));

        // the system administrators are changed
        $admins = System::system_admin_users();
        $user2 = $this->userOf(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->post(admin_url('system'), $this->basicSystemInput([
            'system_admin_users' => array_merge(array_map('strval', $admins), [strval($user2->id)]),
        ]))->assertRedirect(admin_url('system'))->assertSessionHasNoErrors();
        System::clearCache();
        $this->assertContains(strval($user2->id), array_map('strval', System::system_admin_users()));

        // a public form script is saved
        $public_form = $this->publicForm();
        $public_form->setOption('custom_js', 'console.log("demo mode off");');
        $public_form->save();
        $this->assertSame('console.log("demo mode off");', $this->savedPublicForm($public_form)->getOption('custom_js'));

        // and the password is changed
        $this->post(admin_url('auth/change'), [
            'current_password' => self::ADMIN_PASSWORD,
            'password' => 'NewPassw0rd1',
            'password_confirmation' => 'NewPassw0rd1',
        ])->assertRedirect(admin_url('auth/login'));
        $this->assertTrue(\Hash::check('NewPassw0rd1', $this->passwordOf(TestDefine::TESTDATA_USER_LOGINID_ADMIN)));
    }
}
