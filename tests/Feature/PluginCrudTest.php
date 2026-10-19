<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Model\LoginSetting;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Cache;

/**
 * The routes of a CRUD plugin (PluginServiceProvider::pluginRoute, PluginCrudController), run on
 * the TestPluginCrud plugin of the test data (tests/tmpfile/plugins/TestPluginCrud), which keeps
 * its rows in the cache. The OAuth tests link a Google login setting to the plugin; Google is
 * never called: the login only redirects there, and the callback carries no valid state.
 */
class PluginCrudTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const CACHE_KEY = 'test_plugin_crud_rows';

    private LoginUser $admin;

    private Plugin $plugin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();

        $this->admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($this->admin);
        Cache::forget(self::CACHE_KEY);
        $this->plugin = Plugin::where('plugin_name', 'TestPluginCrud')->firstOrFail();
    }

    protected function tearDown(): void
    {
        Cache::forget(self::CACHE_KEY);
        // the plugin list sits in a static request cache: do not hand the linked login setting on
        System::clearCache();
        parent::tearDown();
    }

    public function testTheListShowsTheRows(): void
    {
        $this->get($this->url())
            ->assertOk()
            ->assertSeeText('crud row 1')
            ->assertSeeText('crud row 2');
    }

    public function testTheDetailShowsOneRow(): void
    {
        $this->get($this->url(1))
            ->assertOk()
            ->assertSee('crud row 1')
            ->assertDontSee('crud row 2');
    }

    public function testTheCreateFormAsksForTheName(): void
    {
        $html = (string) $this->get($this->url('create'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input[^>]*\sname="name"/', $html);
    }

    public function testStoreAddsARowAndOpensIt(): void
    {
        $this->post($this->url(), ['name' => 'stored row'])->assertRedirect($this->url(3));

        $this->get($this->url())->assertOk()->assertSeeText('stored row');
    }

    public function testTheEditFormShowsTheCurrentName(): void
    {
        $html = (string) $this->get($this->url(1, 'edit'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/<input(?=[^>]*\sname="name")(?=[^>]*\svalue="crud row 1")[^>]*>/', $html);
    }

    public function testPutUpdatesTheRow(): void
    {
        $this->put($this->url(1), ['name' => 'put row'])->assertRedirect($this->url(1));

        $this->get($this->url(1))->assertOk()->assertSee('put row')->assertDontSee('crud row 1');
    }

    public function testPatchUpdatesTheRow(): void
    {
        $this->patch($this->url(2), ['name' => 'patched row'])->assertRedirect($this->url(2));

        $this->get($this->url(2))->assertOk()->assertSee('patched row')->assertDontSee('crud row 2');
    }

    public function testDeleteRemovesTheRow(): void
    {
        $this->delete($this->url(2))
            ->assertOk()
            ->assertJson(['result' => true, 'redirect' => $this->url()]);

        $this->get($this->url())->assertOk()->assertSeeText('crud row 1')->assertDontSeeText('crud row 2');
    }

    public function testWithoutALoginSettingTheNoAuthPageHasNoLoginForm(): void
    {
        $this->get($this->url('noauth'))
            ->assertOk()
            ->assertSeeText('TestPluginCrud')
            ->assertDontSee('action="' . $this->url('oauth') . '"', false);
    }

    public function testWithoutALoginSettingTheOAuthCallbackGoesBackToTheList(): void
    {
        $this->get($this->url('oauthcallback'))->assertRedirect($this->url());
    }

    public function testWithoutALoginSettingTheOAuthLogoutGoesToTheNoAuthPage(): void
    {
        $this->get($this->url('oauthlogout'))->assertRedirect($this->url('noauth'));
    }

    public function testWithoutAnAccessTokenTheListSendsTheUserToTheOAuthLogin(): void
    {
        $this->linkOAuthLogin();

        $this->get($this->url())->assertRedirect($this->url('noauth'));
        $this->get($this->url('noauth'))
            ->assertOk()
            ->assertSee('action="' . $this->url('oauth') . '"', false);
    }

    public function testTheOAuthLoginRedirectsToTheProvider(): void
    {
        $this->linkOAuthLogin();

        $location = (string) $this->get($this->url('oauth'))->assertStatus(302)->headers->get('Location');

        $this->assertStringStartsWith('https://accounts.google.com/o/oauth2/auth?', $location);
        $this->assertStringContainsString('client_id=test-crud-client', $location);
        $this->assertStringContainsString('redirect_uri=' . urlencode($this->url('oauthcallback')), $location);
    }

    public function testTheOAuthCallbackWithoutAValidStateStoresNoToken(): void
    {
        $setting = $this->linkOAuthLogin();

        $this->get($this->url('oauthcallback') . '?code=not-a-code&state=not-the-state')
            ->assertRedirect($this->url());

        $this->assertNull($this->admin->getSettingValue("plugin_crud_oauth_access_token_{$setting->id}"));
        $this->get($this->url())->assertRedirect($this->url('noauth'));
    }

    public function testTheOAuthLogoutForgetsTheAccessToken(): void
    {
        $setting = $this->linkOAuthLogin();
        $this->admin->setSettingValue("plugin_crud_oauth_access_token_{$setting->id}", 'test-crud-token');
        $this->admin->setSettingValue("plugin_crud_oauth_access_token_expires_at_{$setting->id}", now()->addHour()->toDateTimeString());

        $this->get($this->url())->assertOk()->assertSeeText('crud row 1');

        $this->get($this->url('oauthlogout'))->assertRedirect($this->url('noauth'));
        $this->assertNull($this->admin->getSettingValue("plugin_crud_oauth_access_token_{$setting->id}"));
        $this->get($this->url())->assertRedirect($this->url('noauth'));
    }

    private function linkOAuthLogin(): LoginSetting
    {
        $setting = LoginSetting::create([
            'login_view_name' => 'crud plugin oauth',
            'login_type' => LoginType::OAUTH,
            'active_flg' => 1,
            'options' => [
                'oauth_provider_type' => 'google',
                'oauth_client_id' => 'test-crud-client',
                'oauth_client_secret' => 'test-crud-secret',
            ],
        ]);
        $this->plugin->setOption('crud_auth_oauth', $setting->id);
        $this->plugin->save();
        // the plugin list and the login settings are cached for the request
        System::clearCache();

        return $setting;
    }

    private function url(string|int ...$path): string
    {
        return $this->plugin->getFullUrl(...$path);
    }
}
