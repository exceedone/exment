<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

/**
 * The login setting screen (admin/login_setting): a setting's create/edit/update/delete and its
 * activate buttons, the global login settings and the two-factor setting with its mailed code.
 * The SSO login tests of a setting are in SsoLoginCoverageTest.
 */
class LoginSettingCoverageTest extends FeatureTestBase
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
        // the login settings and the system settings are cached for the request
        System::clearCache();
        parent::tearDown();
    }

    public function testOAuthLoginSettingCrud(): void
    {
        $response = $this->post(admin_url('login_setting'), $this->oauthInput('Claude coverage oauth', 'claude-cov-client'));
        $setting = LoginSetting::query()->where('login_view_name', 'Claude coverage oauth')->firstOrFail();
        $edit_url = route('exment.login_setting.edit', ['id' => $setting->id]);
        $response->assertRedirect($edit_url)->assertSessionHasNoErrors();
        $this->assertSame(LoginType::OAUTH, $setting->login_type);
        $this->assertSame('google', $setting->getOption('oauth_provider_type'));
        $this->assertSame('claude-cov-client', $setting->getOption('oauth_client_id'));

        $this->get(admin_url("login_setting/{$setting->id}/edit"))
            ->assertOk()
            ->assertSee('Claude coverage oauth')
            ->assertSee('claude-cov-client');

        // the edit page sends the login type and the provider as hidden fields
        $this->put(admin_url("login_setting/{$setting->id}"), $this->oauthInput('Claude coverage oauth (put)', 'claude-cov-client-put'))
            ->assertRedirect($edit_url)
            ->assertSessionHasNoErrors();
        $updated = LoginSetting::query()->whereKey($setting->id)->firstOrFail();
        $this->assertSame('Claude coverage oauth (put)', $updated->login_view_name);
        $this->assertSame('claude-cov-client-put', $updated->getOption('oauth_client_id'));

        $this->patch(admin_url("login_setting/{$setting->id}"), $this->oauthInput('Claude coverage oauth (patch)', 'claude-cov-client-patch'))
            ->assertRedirect($edit_url)
            ->assertSessionHasNoErrors();
        $updated = LoginSetting::query()->whereKey($setting->id)->firstOrFail();
        $this->assertSame('Claude coverage oauth (patch)', $updated->login_view_name);
        $this->assertSame('claude-cov-client-patch', $updated->getOption('oauth_client_id'));

        $this->delete(admin_url("login_setting/{$setting->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);
        $this->assertDatabaseMissing($setting->getTable(), ['id' => $setting->id]);
    }

    public function testTheActivateButtonsSwitchTheSettingOnAndOff(): void
    {
        $setting = LoginSetting::create([
            'login_view_name' => 'Claude coverage activate',
            'login_type' => LoginType::OAUTH,
            'active_flg' => 0,
            'options' => [
                'oauth_provider_type' => 'google',
                'oauth_client_id' => 'claude-cov-client',
                'oauth_client_secret' => 'claude-cov-secret',
            ],
        ]);
        System::clearCache();

        $this->post(admin_url("login_setting/{$setting->id}/activate"))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.update_succeeded')]);
        $this->assertTrue((bool) LoginSetting::query()->whereKey($setting->id)->firstOrFail()->active_flg);

        $this->post(admin_url("login_setting/{$setting->id}/deactivate"))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.update_succeeded')]);
        $this->assertFalse((bool) LoginSetting::query()->whereKey($setting->id)->firstOrFail()->active_flg);
    }

    public function testTheGlobalLoginSettingsAreSaved(): void
    {
        // the screen posts every login setting: send the current ones and a new background color
        $before = System::get_system_values(['login']);
        $input = [];
        foreach ($before as $key => $value) {
            // a file field: an upload that is not sent keeps the stored file
            if ($key === 'login_page_image') {
                continue;
            }
            $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }
        $input['login_background_color'] = '#123456';

        $this->post(admin_url('login_setting/postglobal'), $input)
            ->assertRedirect(route('exment.login_setting.index'))
            ->assertSessionHasNoErrors();

        System::clearCache();
        $this->assertSame('#123456', System::login_background_color());
        $this->assertSame((bool) $before['show_default_login_provider'], (bool) System::show_default_login_provider());
        $this->assertSame((bool) $before['complex_password'], (bool) System::complex_password());
    }

    public function testTheTwoFactorSettingIsSavedWithTheMailedCode(): void
    {
        Notification::fake();
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);

        // "send the verify code": stores a code for the administrator and mails it
        $this->post(admin_url('login_setting/2factor-verify'))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('common.message.sendmail_succeeded')])
            ->assertSessionHas(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR, true);
        Notification::assertSentTimes(MailSendJob::class, 1);

        $code = DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $admin->id)
            ->where('verify_type', '2factor_system')
            ->value('verify_code');
        $this->assertNotNull($code);

        // a wrong code is refused and changes nothing
        $this->post(admin_url('login_setting/2factor'), [
            'login_use_2factor' => '1',
            'login_2factor_provider' => Login2FactorProviderType::EMAIL,
            'login_2factor_verify_code' => 'not-the-code',
        ])->assertRedirect()->assertSessionHasErrors('login_2factor_verify_code');
        System::clearCache();
        $this->assertFalse((bool) System::login_use_2factor());

        // the mailed code turns two-factor login on and is used up
        $this->post(admin_url('login_setting/2factor'), [
            'login_use_2factor' => '1',
            'login_2factor_provider' => Login2FactorProviderType::EMAIL,
            'login_2factor_verify_code' => (string) $code,
        ])->assertRedirect(admin_url('login_setting'))->assertSessionHasNoErrors();

        System::clearCache();
        $this->assertTrue((bool) System::login_use_2factor());
        $this->assertSame(Login2FactorProviderType::EMAIL, System::login_2factor_provider());
        $this->assertDatabaseMissing(SystemTableName::EMAIL_CODE_VERIFY, [
            'login_user_id' => $admin->id,
            'verify_type' => '2factor_system',
        ]);
    }

    /**
     * The fields the screen posts for a Google OAuth setting.
     *
     * @return array<string, mixed>
     */
    private function oauthInput(string $login_view_name, string $client_id): array
    {
        return [
            'login_view_name' => $login_view_name,
            'login_type' => LoginType::OAUTH,
            'options' => [
                'oauth_provider_type' => 'google',
                'oauth_client_id' => $client_id,
                'oauth_client_secret' => 'claude-cov-secret',
                'mapping_user_column' => 'email',
            ],
        ];
    }
}
