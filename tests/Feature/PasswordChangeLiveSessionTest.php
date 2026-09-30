<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Auth\SessionPasswordHash;
use Exceedone\Exment\Middleware\AuthenticateSession;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Login\LoginService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;

/**
 * A browser that signed in before the password was changed must be signed out.
 * (Exceedone\Exment\Middleware\AuthenticateSession)
 *
 * Scenario: browser A signs in WITHOUT "remember me" and keeps its session cookie.
 * The password is changed from another browser. Browser A opens a page again.
 *
 * - "SessionIsEnded..." : the browsers that did not change the password are signed out.
 * - "...StaysSignedIn"  : the browser that changed the password, and the administrator who reset
 *                         the password of somebody else, are not signed out.
 * - "Control..."        : only prove that the scenario itself is set up correctly.
 *
 * Browsers are told apart by the session cookie. Sessions are kept in memory while a test runs.
 * Uses login user id 1 (temporary email and password). Everything is rolled back, no mail is sent.
 */
class PasswordChangeLiveSessionTest extends FeatureTestBase
{
    use DatabaseTransactions;
    use PasswordTestTrait;

    protected const OLD_PASSWORD = 'Old-Leaked-2026!';
    protected const NEW_PASSWORD = 'New-Secure-2026!';
    protected const TYPED_PASSWORD = 'Typed-Secret-2026!';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        Notification::fake();

        // Not to depend on the session driver of the environment, and not to leave sessions behind
        config(['session.driver' => 'array']);
        $this->app->make('session')->forgetDrivers();
        $this->app->forgetInstance('session.store');
    }

    // ------------------------------------------------------------------ //
    //  control                                                            //
    // ------------------------------------------------------------------ //

    public function testControlSessionCookieKeepsSignedInWhilePasswordIsUnchanged(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $state = $this->browseWithOnlySessionCookie($session_id);

        $this->assertTrue($state['authenticated'], 'session cookie should keep the browser signed in');
        $this->assertFalse($state['via_remember'], 'sign in should come from the session, not from a remember cookie');
    }

    public function testControlUnknownSessionCookieIsNotSignedIn(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->loginWithoutRemember($login_user);

        $state = $this->browseWithOnlySessionCookie(str_repeat('x', 40));

        $this->assertFalse($state['authenticated'], 'a session id that does not exist must not be signed in');
    }

    public function testLoginKeepsPasswordHashInSession(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $stored = $this->storedSession($session_id) ?? [];

        $this->assertArrayHasKey(SessionPasswordHash::sessionKey(), $stored);
        $this->assertEquals([
            'id' => $login_user->id,
            'hash' => SessionPasswordHash::hash($this->guard(), (string) $this->column($login_user, 'password')),
        ], $stored[SessionPasswordHash::sessionKey()]);
        $this->assertStringNotContainsString((string) $this->column($login_user, 'password'), (string) json_encode($stored), 'hashed password itself must not be kept in the session');
    }

    // ------------------------------------------------------------------ //
    //  the other browsers are signed out                                  //
    // ------------------------------------------------------------------ //

    public function testSessionIsEndedAfterResetByLink(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // another browser: the owner resets the password by the emailed link
        $this->resetAuth();
        $this->postReset($this->createToken($login_user), static::NEW_PASSWORD)->assertRedirect(admin_url('auth/login'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsEnded($session_id);
    }

    public function testSessionIsEndedAfterChangeOnAccountSettingScreen(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // another browser: the owner signs in and changes the password
        $other_session_id = $this->loginWithoutRemember($login_user);
        $this->browser($other_session_id)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsEnded($session_id);
    }

    public function testSessionIsEndedAfterChangeOnChangePasswordScreen(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // another browser: the owner signs in and changes the password
        $other_session_id = $this->loginWithoutRemember($login_user);
        $this->browser($other_session_id)->postAs(admin_url('auth/change'), [
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/login'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsEnded($session_id);
    }

    public function testSessionIsEndedAfterResetByAdministrator(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // login user screen, exment:resetpassword and import call the service the same way
        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsEnded($session_id);
    }

    public function testSessionIsEndedWhenPasswordIsChangedWithoutExment(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // ex. updated on the database directly
        \DB::table('login_users')->where('id', $login_user->id)->update(['password' => Hash::make(static::NEW_PASSWORD)]);

        $this->assertSessionIsEnded($session_id);
    }

    public function testEndedSessionIsRejectedOnWebApi(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // not getJson(). It does not send cookies
        $this->browser($session_id)->get(admin_url('webapi/me'))->assertStatus(200);

        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        $this->browser($session_id)->get(admin_url('webapi/me'))->assertStatus(401);
    }

    public function testEndedSessionIsSentToLoginPageWithMessage(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);
        $this->browser($session_id)->withSession(['kept_by_previous_user' => 'secret'])->get(admin_url(''));
        $this->assertArrayHasKey('kept_by_previous_user', $this->storedSession($session_id) ?? []);

        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        $response = $this->browser($session_id)->get(admin_url(''));
        $response->assertRedirect(admin_url('auth/login'));

        // data of the session is thrown away, not only the sign in
        $session_id = $this->sessionIdOf($response);
        $stored = $this->storedSession($session_id) ?? [];
        $this->assertArrayNotHasKey($this->guard()->getName(), $stored);
        $this->assertArrayNotHasKey(SessionPasswordHash::sessionKey(), $stored);
        $this->assertArrayNotHasKey('kept_by_previous_user', $stored);

        $message = exmtrans('login.signed_out_password_changed');
        $this->browser($session_id)->get(admin_url('auth/login'))->assertStatus(200)->assertSee($message);
        // only once
        $this->browser($session_id)->get(admin_url('auth/login'))->assertStatus(200)->assertDontSee($message);
    }

    /**
     * Change password screen is in the routes that do not check login, so the ended session reaches the controller.
     */
    public function testEndedSessionSendingChangePasswordScreenIsSentToLoginPage(): void
    {
        $this->assertEndedSessionSendingChangePasswordScreenIsSentToLoginPage(static::OLD_PASSWORD);
    }

    public function testEndedSessionSendingChangePasswordScreenWithNewPasswordIsSentToLoginPage(): void
    {
        // the browser types the password set by the other browser as the current one
        $this->assertEndedSessionSendingChangePasswordScreenIsSentToLoginPage(static::NEW_PASSWORD);
    }

    public function testLoginPageShownToEndedSessionHasCsrfToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        // login page is the first page the browser opens after the change
        $response = $this->browser($session_id)->get(admin_url('auth/login'));
        $response->assertStatus(200)->assertSee(exmtrans('login.signed_out_password_changed'));
        if (preg_match('/name="_token" value="([^"]+)"/', (string) $response->getContent(), $matches) !== 1) {
            $this->fail('login form has no csrf token');
        }

        // and the form can be sent, csrf token is verified
        $session_id = $this->sessionIdOf($response);
        $this->resetAuth();
        $this->withCookie($this->sessionCookieName(), $session_id)
            ->from(admin_url('auth/login'))
            ->post(admin_url('auth/login'), [
                '_token' => $matches[1],
                'username' => $login_user->base_user->getValue('user_code'),
                'password' => static::NEW_PASSWORD,
            ])->assertRedirect(admin_url(''));
        $this->assertAuthenticatedAs($login_user, 'admin');
    }

    public function testMessageIsKeptUntilLoginPageIsShown(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        // the browser sends web api and pjax requests before login page is shown
        $response = $this->browser($session_id)->get(admin_url('webapi/me'));
        $response->assertStatus(401);
        $new_session_id = $this->sessionIdOf($response);
        $this->browser($new_session_id)->get(admin_url('webapi/me'))->assertStatus(401);
        $this->browser($new_session_id)->get(admin_url(''), ['X-PJAX' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->assertRedirect(admin_url('auth/login'));
        $this->browser($new_session_id)->get(admin_url('auth/login'), ['X-PJAX' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->assertStatus(200);

        $this->browser($new_session_id)->get(admin_url('auth/login'))
            ->assertStatus(200)
            ->assertSee(exmtrans('login.signed_out_password_changed'));
    }

    public function testMessageIsNotShownToBrowserThatWasNotSignedOut(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // logout by the user
        $response = $this->browser($session_id)->get(admin_url('auth/logout'));
        $response->assertRedirect();

        $this->browser($this->sessionIdOf($response))->get(admin_url('auth/login'))
            ->assertStatus(200)
            ->assertDontSee(exmtrans('login.signed_out_password_changed'));
    }

    public function testEndingSessionDoesNotRenewRememberToken(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        // the owner signs in with new password, "remember me" checked
        $this->resetAuth();
        $response = $this->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => static::NEW_PASSWORD,
            'remember' => 1,
        ]);
        $cookie = $response->getCookie($this->guard()->getRecallerName());
        $this->assertNotNull($cookie, 'login with remember should issue the remember cookie');
        $remember_token = $this->column($login_user, 'remember_token');
        $this->assertNotEmpty($remember_token);

        $this->assertSessionIsEnded($session_id);

        $this->assertSame($remember_token, $this->column($login_user, 'remember_token'), 'remember token of the owner must not be renewed');
        $state = $this->browseWithOnlyRememberCookie((string) $cookie->getValue());
        $this->assertTrue($state['authenticated'], 'remember cookie of the owner should keep working');
        $this->assertTrue($state['via_remember']);
    }

    public function testRememberCookieIssuedWithPreviousPasswordIsRejected(): void
    {
        $login_user = $this->prepareLoginUser();
        $this->resetAuth();
        $response = $this->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => static::OLD_PASSWORD,
            'remember' => 1,
        ]);
        $cookie = $response->getCookie($this->guard()->getRecallerName());
        $this->assertNotNull($cookie, 'login with remember should issue the remember cookie');
        $state = $this->browseWithOnlyRememberCookie((string) $cookie->getValue());
        $this->assertTrue($state['authenticated'], 'remember cookie should sign in while password is unchanged');

        // password is changed, remember token is left as it is
        \DB::table('login_users')->where('id', $login_user->id)->update(['password' => Hash::make(static::NEW_PASSWORD)]);

        $state = $this->browseWithOnlyRememberCookie((string) $cookie->getValue());
        $this->assertFalse($state['authenticated'], 'remember cookie issued with previous password still signs in');
    }

    // ------------------------------------------------------------------ //
    //  the browser that changed the password stays signed in              //
    // ------------------------------------------------------------------ //

    public function testBrowserThatChangedPasswordOnAccountSettingScreenStaysSignedIn(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($session_id)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsAlive($this->sessionIdOf($response));
    }

    public function testBrowserThatChangedPasswordOnChangePasswordScreenStaysSignedIn(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($session_id)->postAs(admin_url('auth/change'), [
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/login'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsAlive($this->sessionIdOf($response));
    }

    /**
     * The session is saved as a whole by every request. A request that started before the change (ex. backup)
     * ends after it, and saves the session as it read it: with the hash of the previous password.
     */
    public function testBrowserThatChangedPasswordStaysSignedInWhenRequestStartedBeforeChangeEndsAfterIt(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);
        $read_before_change = $this->storedSession($session_id) ?? [];

        $response = $this->browser($session_id)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);
        $this->assertSame($session_id, $this->sessionIdOf($response));

        $this->app->make('session.store')->getHandler()->write($session_id, serialize($read_before_change));

        $this->assertSessionIsAlive($session_id);
        $this->assertSame(
            SessionPasswordHash::hash($this->guard(), (string) $this->column($login_user, 'password')),
            ($this->storedSession($session_id) ?? [])[SessionPasswordHash::sessionKey()]['hash'] ?? null,
            'hash of the new password should be kept in the session again'
        );
    }

    public function testBrowserThatChangedPasswordIsSignedOutWhenPasswordIsChangedAgainFromAnotherBrowser(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($session_id)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/setting'));
        $session_id = $this->sessionIdOf($response);
        $this->assertSessionIsAlive($session_id);

        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::TYPED_PASSWORD]);
        $this->assertPasswordIs($login_user, static::TYPED_PASSWORD);

        $this->assertSessionIsEnded($session_id);
    }

    public function testBrowserStaysSignedInWhenSavingNewPasswordFails(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        // ex. the row is locked
        $fails = true;
        LoginUser::updating(function () use (&$fails) {
            if ($fails) {
                throw new \RuntimeException('login user is not saved');
            }
        });
        $response = $this->browser($session_id)->postAs(admin_url('auth/change'), [
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $fails = false;
        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);

        $this->assertSessionIsAlive($this->sessionIdOf($response));
    }

    public function testAdministratorStaysSignedInAfterResettingOwnPassword(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($session_id)->putLoginUser($login_user, static::NEW_PASSWORD);
        $response->assertRedirect();
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsAlive($this->sessionIdOf($response));
    }

    public function testAdministratorStaysSignedInWhenOwnResetIsRolledBack(): void
    {
        $this->useFailingMailer();
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($session_id)->putLoginUser($login_user, static::NEW_PASSWORD, ['send_password' => '1']);
        $response->assertRedirect();
        $this->assertPasswordIs($login_user, static::OLD_PASSWORD);

        $this->assertSessionIsAlive($this->sessionIdOf($response));
    }

    public function testAdministratorStaysSignedInAfterResettingAnotherUser(): void
    {
        $administrator = $this->prepareLoginUser();
        $login_user = $this->createLoginUser();
        $administrator_session_id = $this->loginWithoutRemember($administrator);
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($administrator_session_id)->putLoginUser($login_user, static::NEW_PASSWORD);
        $response->assertRedirect();
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsAlive($this->sessionIdOf($response));
        $this->assertSessionIsEnded($session_id);
    }

    public function testSessionIsKeptWhenAnotherUserSignsInOnSameBrowser(): void
    {
        $administrator = $this->prepareLoginUser();
        $login_user = $this->createLoginUser();
        $session_id = $this->loginWithoutRemember($administrator);

        // the login form is sent again without logout
        $response = $this->browser($session_id)->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => static::OLD_PASSWORD,
        ]);
        $this->assertAuthenticatedAs($login_user, 'admin');

        $this->assertSessionIsAlive($this->sessionIdOf($response));
    }

    public function testSessionIsKeptWhenUserIsSwitchedWithoutLogin(): void
    {
        $administrator = $this->prepareLoginUser();
        $login_user = $this->createLoginUser();
        $session_id = $this->loginWithoutRemember($administrator);

        // ex. a plugin signs another user in. The hash kept in the session is of the administrator
        $this->browser($session_id)->be($login_user, 'admin')->get(admin_url('auth/setting'))->assertStatus(200);

        $this->assertAuthenticatedAs($login_user, 'admin');
    }

    /**
     * Signing in by SSO is not a change of the password.
     */
    public function testSessionIsKeptWhenSameUserSignsInBySsoOnAnotherBrowser(): void
    {
        $callback_url = $this->prepareSsoLogin($this->createUser());
        $session_id = $this->sessionIdOf($this->loginBySso($callback_url));
        $this->assertSessionIsAlive($session_id);

        $another_session_id = $this->sessionIdOf($this->loginBySso($callback_url));
        $this->assertNotSame($session_id, $another_session_id);

        $this->assertSessionIsAlive($session_id);
        $this->assertSessionIsAlive($another_session_id);
    }

    // ------------------------------------------------------------------ //
    //  "remember me" of the browser that changed the password             //
    // ------------------------------------------------------------------ //

    public function testBrowserThatChangedPasswordOnAccountSettingScreenIsStillRemembered(): void
    {
        $login_user = $this->prepareLoginUser();
        $browser = $this->loginAsRememberedBrowser($login_user);

        $response = $this->rememberedBrowser($browser)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertRememberCookieIsIssuedAgain($response, $browser['remember']);
    }

    public function testBrowserThatChangedPasswordOnChangePasswordScreenIsStillRemembered(): void
    {
        $login_user = $this->prepareLoginUser();
        $browser = $this->loginAsRememberedBrowser($login_user);

        $response = $this->rememberedBrowser($browser)->postAs(admin_url('auth/change'), [
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/login'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertRememberCookieIsIssuedAgain($response, $browser['remember']);
    }

    public function testRememberCookieIsNotIssuedToBrowserThatIsNotRemembered(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $response = $this->browser($session_id)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertNull($response->getCookie($this->guard()->getRecallerName()), 'remember cookie is issued to the browser that did not have it');
    }

    public function testRememberCookieIsNotIssuedForCookieThatDoesNotSignIn(): void
    {
        $login_user = $this->prepareLoginUser();
        $browser = $this->loginAsRememberedBrowser($login_user);
        $browser['remember'] = $login_user->id . '|' . str_repeat('x', 60) . '|x';

        $response = $this->rememberedBrowser($browser)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertNull($response->getCookie($this->guard()->getRecallerName()), 'remember cookie is issued for the cookie that was not valid');
    }

    public function testAdministratorIsStillRememberedAfterResettingAnotherUser(): void
    {
        $administrator = $this->prepareLoginUser();
        $login_user = $this->createLoginUser();
        $browser = $this->loginAsRememberedBrowser($administrator);
        $cookie = $this->loginWithRemember($login_user);

        $response = $this->rememberedBrowser($browser)->putLoginUser($login_user, static::NEW_PASSWORD);
        $response->assertRedirect();
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertNull($response->getCookie($this->guard()->getRecallerName()), 'remember cookie of the administrator does not have to be issued again');
        $this->assertTrue($this->browseWithOnlyRememberCookie($browser['remember'])['authenticated'], 'remember cookie of the administrator should keep working');
        $this->assertFalse($this->browseWithOnlyRememberCookie($cookie)['authenticated'], 'remember cookie of the user still signs in');
    }

    // ------------------------------------------------------------------ //
    //  other                                                              //
    // ------------------------------------------------------------------ //

    public function testSessionIsEndedAfterImport(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);

        $this->resetAuth();
        $this->importLoginUser($login_user->refresh(), ['password' => static::NEW_PASSWORD]);
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->assertSessionIsEnded($session_id);
    }

    /**
     * Every route that signs in by the session checks the session before it checks login.
     */
    public function testSessionIsCheckedBeforeLoginIsChecked(): void
    {
        $groups = app('router')->getMiddlewareGroups();
        $expects = [
            'admin' => 'admin.auth',
            'adminapioauth' => 'admin.auth',
            'admin_plugin_public' => 'admin.auth',
            'adminwebapi' => 'adminwebapi.auth',
            'admin_anonymous' => 'admin.login',
        ];

        foreach ($expects as $group => $auth) {
            $this->assertArrayHasKey($group, $groups);
            $session = array_search('admin.auth-session', $groups[$group], true);
            $login = array_search($auth, $groups[$group], true);

            $this->assertNotFalse($session, "{$group}: session is not checked");
            $this->assertNotFalse($login, "{$group}: {$auth} is not found");
            $this->assertLessThan($login, $session, "{$group}: session is checked after login is checked");
            $this->assertCount(1, array_keys($groups[$group], 'admin.auth-session', true), "{$group}: session is checked twice");
        }

        // token of api is not related to the session
        foreach (['adminapi', 'adminapi_anonymous', 'publicformapi'] as $group) {
            $this->assertNotContains('admin.auth-session', $groups[$group], "{$group}: api does not have session");
        }
    }

    /**
     * Login page is not shown when SSO is forced: the browser is redirected to the provider.
     */
    public function testMessageIsKeptWhenLoginPageIsNotShown(): void
    {
        $message = exmtrans('login.signed_out_password_changed');
        $session = $this->app->make('session.store');
        $session->put(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_LOGOUT, true);
        $request = Request::create(admin_url('auth/login'), 'GET');
        $request->setLaravelSession($session);
        $this->app->instance('request', $request);

        $shown = null;
        (new AuthenticateSession())->handle($request, function () use ($session, &$shown) {
            $shown = $session->get('status_error');
            return redirect('https://sso.example.invalid/login');
        });
        $this->assertSame($message, $shown);
        $this->assertTrue($session->has(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_LOGOUT), 'message is thrown away though login page was not shown');

        (new AuthenticateSession())->handle($request, function () use ($session, &$shown) {
            $shown = $session->get('status_error');
            return response('login page');
        });
        $this->assertSame($message, $shown);
        $this->assertFalse($session->has(Define::SYSTEM_KEY_SESSION_PASSWORD_CHANGED_LOGOUT), 'message is kept though login page was shown');
    }

    public function testSessionSignedInBeforeThisCheckExistedIsKept(): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);
        $this->forgetPasswordHashInSession($session_id);

        $this->assertSessionIsAlive($session_id);
        $this->assertArrayHasKey(SessionPasswordHash::sessionKey(), $this->storedSession($session_id), 'password hash should be kept from the first request');

        // and it is signed out by the change of password after that
        $this->resetAuth();
        LoginService::resetPassword($login_user->refresh(), ['password' => static::NEW_PASSWORD]);

        $this->assertSessionIsEnded($session_id);
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                            //
    // ------------------------------------------------------------------ //

    /**
     * Sign in on the login screen as a new browser, "remember me" not checked.
     *
     * @return string session id as the browser holds it in the session cookie (decrypted)
     */
    protected function loginWithoutRemember(LoginUser $login_user): string
    {
        $this->resetAuth();
        $response = $this->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => static::OLD_PASSWORD,
        ]);
        $this->assertAuthenticatedAs($login_user, 'admin');
        $this->assertNull($response->getCookie($this->guard()->getRecallerName()), 'remember cookie should not be issued');

        $session_id = $this->sessionIdOf($response);
        $this->assertNotNull($this->storedSession($session_id), 'session should be stored');

        return $session_id;
    }

    /**
     * Sign in on the login screen as a new browser, "remember me" checked.
     *
     * @return array{session: string, remember: string} cookies as the browser holds them (decrypted)
     */
    protected function loginAsRememberedBrowser(LoginUser $login_user): array
    {
        $this->resetAuth();
        $response = $this->postAs(admin_url('auth/login'), [
            'username' => $login_user->base_user->getValue('user_code'),
            'password' => static::OLD_PASSWORD,
            'remember' => 1,
        ]);
        $this->assertAuthenticatedAs($login_user, 'admin');

        return [
            'session' => $this->sessionIdOf($response),
            'remember' => $this->cookieOf($response, $this->guard()->getRecallerName()),
        ];
    }

    /**
     * Next request is sent by the browser that holds the session cookie and the remember cookie.
     *
     * @param array{session: string, remember: string} $browser
     * @return $this
     */
    protected function rememberedBrowser(array $browser)
    {
        $this->resetAuth();

        return $this->withCookies([
            $this->sessionCookieName() => $browser['session'],
            $this->guard()->getRecallerName() => $browser['remember'],
        ]);
    }

    /**
     * @param TestResponse $response
     * @param string $before remember cookie the browser had when it changed the password
     */
    protected function assertRememberCookieIsIssuedAgain(TestResponse $response, string $before): void
    {
        $cookie = $this->cookieOf($response, $this->guard()->getRecallerName());
        $this->assertNotSame($before, $cookie);

        $state = $this->browseWithOnlyRememberCookie($cookie);
        $this->assertTrue($state['authenticated'], 'browser that changed the password is not remembered any more');
        $this->assertTrue($state['via_remember']);

        $this->assertFalse($this->browseWithOnlyRememberCookie($before)['authenticated'], 'remember cookie issued before the change still signs in');
    }

    /**
     * Next request is sent by the browser that holds this session cookie, and nothing else.
     *
     * @return $this
     */
    protected function browser(string $session_id)
    {
        $this->resetAuth();

        return $this->withCookie($this->sessionCookieName(), $session_id);
    }

    /**
     * Session id the browser holds after it received the response.
     *
     * @param TestResponse $response
     */
    protected function sessionIdOf(TestResponse $response): string
    {
        return $this->cookieOf($response, $this->sessionCookieName());
    }

    /**
     * Open the admin top page as a browser that has nothing but the session cookie.
     *
     * @return array{authenticated: bool, via_remember: bool, status: int, location: string}
     */
    protected function browseWithOnlySessionCookie(string $session_id): array
    {
        return $this->browse([$this->sessionCookieName() => $session_id]);
    }

    protected function assertSessionIsEnded(string $session_id): void
    {
        $state = $this->browseWithOnlySessionCookie($session_id);

        $this->assertFalse(
            $state['authenticated'],
            'browser signed in before the password change is still signed in (landed on: ' . $state['location'] . ', status ' . $state['status'] . ')'
        );
    }

    protected function assertSessionIsAlive(string $session_id): void
    {
        $state = $this->browseWithOnlySessionCookie($session_id);

        $this->assertTrue(
            $state['authenticated'],
            'browser is signed out (landed on: ' . $state['location'] . ', status ' . $state['status'] . ')'
        );
        $this->assertFalse($state['via_remember']);
    }

    /**
     * The browser has change password screen open, the password is changed from another browser, then the screen is sent.
     *
     * @param string $current_password password the browser types as the current one
     */
    protected function assertEndedSessionSendingChangePasswordScreenIsSentToLoginPage(string $current_password): void
    {
        $login_user = $this->prepareLoginUser();
        $session_id = $this->loginWithoutRemember($login_user);
        $this->browser($session_id)->get(admin_url('auth/change'))->assertStatus(200);

        // another browser: the owner signs in and changes the password
        $other_session_id = $this->loginWithoutRemember($login_user);
        $this->browser($other_session_id)->putSetting([
            'current_password' => static::OLD_PASSWORD,
            'password' => static::NEW_PASSWORD,
            'password_confirmation' => static::NEW_PASSWORD,
        ])->assertRedirect(admin_url('auth/setting'));
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $response = $this->browser($session_id)->postAs(admin_url('auth/change'), [
            'current_password' => $current_password,
            'password' => static::TYPED_PASSWORD,
            'password_confirmation' => static::TYPED_PASSWORD,
        ]);
        $response->assertRedirect(admin_url('auth/login'));
        $this->assertPasswordNotFlashed();
        $this->assertPasswordIs($login_user, static::NEW_PASSWORD);

        $this->browser($this->sessionIdOf($response))->get(admin_url('auth/login'))
            ->assertStatus(200)
            ->assertSee(exmtrans('login.signed_out_password_changed'));
    }

    /**
     * Make the stored session the same as the one signed in before the password hash was kept.
     */
    protected function forgetPasswordHashInSession(string $session_id): void
    {
        $data = $this->storedSession($session_id) ?? [];
        $this->assertArrayHasKey(SessionPasswordHash::sessionKey(), $data);
        unset($data[SessionPasswordHash::sessionKey()]);

        $this->app->make('session.store')->getHandler()->write($session_id, serialize($data));
    }

    protected function sessionCookieName(): string
    {
        return (string) config('session.cookie');
    }
}
