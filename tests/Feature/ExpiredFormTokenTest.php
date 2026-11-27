<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Route;

/**
 * A request with an expired or missing form token is not run, and the answer says so: 419 for a
 * script, a redirect back (with the message) for a page. It was an error page with 200.
 *
 * The check is off while the tests run (Laravel's runningUnitTests()), so the tests use it with
 * that turned off, on a route in the same middleware groups as the pages of the plugins.
 */
class ExpiredFormTokenTest extends FeatureTestBase
{
    /** @var int */
    public static $ran = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        static::$ran = 0;

        $this->app->bind(VerifyCsrfToken::class, CheckedVerifyCsrfToken::class);
        Route::group(['prefix' => config('admin.route.prefix'), 'middleware' => ['adminweb', 'admin']], function () {
            Route::post('exment-token-test', function () {
                ExpiredFormTokenTest::$ran++;
                return response()->json(['ran' => true]);
            });
        });

        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function url(): string
    {
        return admin_url('exment-token-test');
    }

    public function testAFormPostGoesBack(): void
    {
        $back = admin_url('');
        $response = $this->from($back)->post($this->url(), ['a' => '1', '_token' => 'wrong']);

        $response->assertRedirect($back);
        $this->assertSame(0, static::$ran, 'not run');
    }

    public function testAFormPostWithoutATokenGoesBack(): void
    {
        $back = admin_url('');
        $response = $this->from($back)->post($this->url(), ['a' => '1']);

        $response->assertRedirect($back);
        $this->assertSame(0, static::$ran);
    }

    public function testAScriptGets419(): void
    {
        $this->post($this->url(), ['a' => '1'], ['X-Requested-With' => 'XMLHttpRequest'])
            ->assertStatus(419)
            ->assertJsonStructure(['message']);
        $this->assertSame(0, static::$ran);
    }

    public function testAFetchWithJsonGets419(): void
    {
        $this->postJson($this->url(), ['a' => 1], ['X-CSRF-TOKEN' => 'wrong'])
            ->assertStatus(419);
        $this->assertSame(0, static::$ran);
    }

    public function testPjaxGoesBack(): void
    {
        $back = admin_url('');
        $this->from($back)->post($this->url(), ['a' => '1'], ['X-PJAX' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])
            ->assertRedirect($back);
        $this->assertSame(0, static::$ran);
    }

    public function testWhatWasTypedComesBackWithoutPasswordsAndTokens(): void
    {
        $back = admin_url('');
        $this->from($back)->post($this->url(), [
            'title' => 'my title',
            'value' => ['text' => 'my text', 'api_key' => 'k-1'],
            'password' => 'secret-1',
            'password_confirmation' => 'secret-1',
            'current_password' => 'secret-0',
            'client_secret' => 's-1',
            '_token' => 'wrong',
        ])->assertRedirect($back);

        $this->assertSame('my title', session()->getOldInput('title'));
        $this->assertSame('my text', session()->getOldInput('value.text'));
        foreach (['password', 'password_confirmation', 'current_password', 'client_secret', '_token', 'value.api_key'] as $key) {
            $this->assertNull(session()->getOldInput($key), $key);
        }
        $this->assertSame(0, static::$ran);
    }

    public function testTheLoginPageSaysThePageExpired(): void
    {
        \Auth::guard('admin')->logout();
        session()->flush();
        $login = admin_url('auth/login');

        $response = $this->from($login)->post($login, ['username' => 'someone', 'password' => 'p', '_token' => 'wrong']);
        $response->assertRedirect($login);
        $this->assertSame('someone', session()->getOldInput('username'));
        $this->assertNull(session()->getOldInput('password'));

        $html = (string) $this->get($login)->getContent();
        $this->assertStringContainsString('exm-login-error', $html);
        $this->assertStringContainsString(e(exmtrans('error.expired_error_reinput')), $html);
        $this->assertStringContainsString('value="someone"', $html);
    }

    public function testTheRightTokenRuns(): void
    {
        $this->get(admin_url(''));
        $token = session()->token();

        $this->post($this->url(), ['a' => '1', '_token' => $token])->assertOk()->assertJson(['ran' => true]);
        $this->postJson($this->url(), ['a' => 1], ['X-CSRF-TOKEN' => $token])->assertOk();
        $this->assertSame(2, static::$ran);
    }
}

/** The check as it is on a site (it is skipped while the tests run). */
class CheckedVerifyCsrfToken extends VerifyCsrfToken
{
    protected function runningUnitTests()
    {
        return false;
    }
}
