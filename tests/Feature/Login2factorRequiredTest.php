<?php

namespace Exceedone\Exment\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Testing\TestResponse;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\LoginType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Middleware\VerifyCsrfToken;
use Exceedone\Exment\Model\Auth2factorRecoveryCode;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Model\RoleGroupUserOrganization;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Auth2factor\Auth2factorRecoveryCodeService;
use Exceedone\Exment\Services\Auth2factor\Providers\Email;
use Exceedone\Exment\Services\Auth2factor\Providers\Google;
use Exceedone\Exment\Services\Login\LoginHistoryService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use PragmaRX\Google2FA\Google2FA;

/**
 * 2factor authentication required by role group:
 * - login setting "login_2factor_target_role_group" (YES/NO switch): NO = every user (default, as before) / YES = specified by role group.
 * - role group flag "require_2factor". A user who belongs to no role group is also required.
 * - a required user who has not set up 2factor (Google authenticator) can use Exment for the grace period
 *   (setting "login_2factor_setup_grace_minutes", default 30) with a warning; after that, every page is redirected to the setup.
 * - recovery codes: 10 one-time codes issued at the first 2factor login, shown once, usable instead of the one-time password,
 *   and re-issued from the user setting.
 *
 * This test creates its own users / role groups and rolls everything back, so it does not depend on `exment:inittest` data.
 * The Google provider is used unless a test needs the e-mail provider, so no mail is sent at login.
 */
class Login2factorRequiredTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const PASSWORD = 'Login2factor-2026!';
    protected const IP = '133.242.0.1';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        \Notification::fake();

        config(['exment.login_use_2factor' => true]);
        // the one-time password forms are throttled per IP by a cache that outlives the test: not throttled here
        config(['exment.throttle' => false]);
        // the providers are registered by the "initialize" middleware, so register them for the tests that do not send a request
        Auth2factorService::providers(Login2FactorProviderType::EMAIL, Email::class);
        Auth2factorService::providers(Login2FactorProviderType::GOOGLE, Google::class);
        System::login_use_2factor(true);
        System::login_2factor_provider(Login2FactorProviderType::GOOGLE);
        System::login_2factor_target_role_group(false);
        System::login_2factor_setup_grace_minutes(Auth2factorService::DEFAULT_SETUP_GRACE_MINUTES);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ //
    //  Who is required                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Default (the role group switch is NO): every user is required, as before the switch was added.
     */
    public function testDefaultTargetIsAllUsers(): void
    {
        $this->assertFalse(System::login_2factor_target_role_group());
        $this->assertFalse(Auth2factorService::isTargetRoleGroup());

        $noGroup = $this->createLoginUser();
        $inGroup = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(false), $inGroup);

        $this->assertTrue($this->isRequired($noGroup));
        $this->assertTrue($this->isRequired($inGroup));
    }

    /**
     * 2factor is disabled (config or system setting): nobody is required, whatever the role groups say.
     */
    public function testNobodyIsRequiredWhen2factorIsDisabled(): void
    {
        $this->useRoleGroupTarget();
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);
        $this->assertTrue($this->isRequired($login_user), 'precondition');

        config(['exment.login_use_2factor' => false]);
        $this->assertFalse($this->isRequired($login_user));

        config(['exment.login_use_2factor' => true]);
        System::login_use_2factor(false);
        $this->assertFalse($this->isRequired($login_user));
    }

    /**
     * The role group switch is YES: only the users of the role groups whose flag is on, and the users who belong to no role group.
     */
    public function testRoleGroupTargetDecidesByRoleGroups(): void
    {
        $this->useRoleGroupTarget();
        $this->assertTrue(Auth2factorService::isTargetRoleGroup());

        $required = $this->createRoleGroup(true);
        $notRequired = $this->createRoleGroup(false);

        $inRequired = $this->createLoginUser();
        $this->addToRoleGroup($required, $inRequired);

        $inNotRequired = $this->createLoginUser();
        $this->addToRoleGroup($notRequired, $inNotRequired);

        $inBoth = $this->createLoginUser();
        $this->addToRoleGroup($notRequired, $inBoth);
        $this->addToRoleGroup($required, $inBoth);

        $noGroup = $this->createLoginUser();

        $this->assertTrue($this->isRequired($inRequired), 'in a required role group');
        $this->assertFalse($this->isRequired($inNotRequired), 'only in a not required role group');
        $this->assertTrue($this->isRequired($inBoth), 'one of the role groups is required');
        $this->assertTrue($this->isRequired($noGroup), 'no role group: required');
        $this->assertFalse(Auth2factorService::isRequiredFor(null), 'no user: cannot be judged');
    }

    /**
     * The user belongs to the required role group through the organization.
     */
    public function testRoleGroupTargetThroughOrganization(): void
    {
        if (!System::organization_available()) {
            $this->markTestSkipped('organization is not available in this environment.');
        }
        $this->useRoleGroupTarget();

        $login_user = $this->createLoginUser();
        $org = $this->createOrganizationWithUser($login_user);
        $notRequired = $this->createRoleGroup(false);
        $this->addToRoleGroup($notRequired, $login_user);
        $this->assertFalse($this->isRequired($login_user), 'precondition: not required as a user');

        // through the login as well: the organizations are read by the "admin.auth-2factor" middleware, before the admin page is bootstrapped
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertStatus(200);

        $required = $this->createRoleGroup(true);
        $this->addOrganizationToRoleGroup($required, $org);

        $this->assertTrue($this->isRequired($login_user), 'required through the organization');
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
    }

    /**
     * Turning the flag of the role group on and off applies at once (the role groups are cached).
     */
    public function testChangingTheFlagApplies(): void
    {
        $this->useRoleGroupTarget();
        $role_group = $this->createRoleGroup(false);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($role_group, $login_user);
        $this->assertFalse($this->isRequired($login_user));

        $role_group->require_2factor = true;
        $role_group->save();
        $this->assertTrue($this->isRequired($login_user));

        $role_group->require_2factor = false;
        $role_group->save();
        $this->assertFalse($this->isRequired($login_user));
    }

    /**
     * "Set up" depends on the provider: e-mail needs nothing, Google needs the registered authenticator.
     */
    public function testIsSetUpByProvider(): void
    {
        $login_user = $this->createLoginUser();

        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $this->assertTrue(Auth2factorService::isSetUp($login_user));

        System::login_2factor_provider(Login2FactorProviderType::GOOGLE);
        $this->assertFalse(Auth2factorService::isSetUp($login_user));

        $this->setUpGoogle($login_user, true);
        $this->assertTrue(Auth2factorService::isSetUp($this->freshLoginUser($login_user)));
    }

    /**
     * Grace minutes: the setting, never negative, default 30.
     */
    public function testSetupGraceMinutesSetting(): void
    {
        $this->assertSame(30, Auth2factorService::getSetupGraceMinutes());

        System::login_2factor_setup_grace_minutes(0);
        $this->assertSame(0, Auth2factorService::getSetupGraceMinutes());

        System::login_2factor_setup_grace_minutes(-5);
        $this->assertSame(0, Auth2factorService::getSetupGraceMinutes());

        System::login_2factor_setup_grace_minutes(90);
        $this->assertSame(90, Auth2factorService::getSetupGraceMinutes());
    }

    // ------------------------------------------------------------------ //
    //  Login flow                                                         //
    // ------------------------------------------------------------------ //

    /**
     * Target "role_group", the user is not required: logs in without 2factor, no one-time password is issued,
     * and the login history is "not subject" (null).
     */
    public function testNotRequiredUserLogsInWithout2factor(): void
    {
        $this->useRoleGroupTarget();
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(false), $login_user);

        $this->postLogin($login_user)->assertRedirect(admin_url(''));
        $this->get(admin_url('auth/setting'))->assertStatus(200);

        $this->assertSame(0, $this->countEmailCodes($login_user), 'no one-time password is sent');
        $this->assertNull($this->historiesOf($login_user)[0]->auth_2factor_verified, 'not subject to 2factor');
        $this->assertNull($this->freshLoginUser($login_user)->auth2fa_required_at);
    }

    /**
     * Target "role_group", the user is required, e-mail provider: nothing to set up, so the one-time password is asked at once.
     */
    public function testRequiredUserWithEmailProviderIsAskedAtOnce(): void
    {
        $this->useRoleGroupTarget();
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));

        $this->assertSame(1, $this->countEmailCodes($login_user), 'the one-time password is sent');
        $this->assertFalse($this->historiesOf($login_user)[0]->auth_2factor_verified);
        $this->assertNull($this->freshLoginUser($login_user)->auth2fa_required_at, 'no grace period for e-mail');
    }

    /**
     * Target "role_group", the user is required, Google authenticator not registered yet:
     * the user can use Exment with a warning for the grace period, counted from the first request after login.
     * After the period, every page is redirected to the 2factor page, except the 2factor pages themselves.
     */
    public function testRequiredUserWithoutSetupHasGracePeriod(): void
    {
        $this->useRoleGroupTarget(30);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->postLogin($login_user)->assertStatus(302);

        // within the period: the page opens with the warning
        $response = $this->get(admin_url('auth/setting'));
        $response->assertStatus(200);
        $response->assertSee(exmtrans('2factor.message.setup_required_title'));
        $response->assertSee(admin_url('auth-2factor'));

        $required_at = $this->freshLoginUser($login_user)->auth2fa_required_at;
        $this->assertNotNull($required_at, 'the start of the grace period is recorded');
        $this->assertFalse($this->historiesOf($login_user)[0]->auth_2factor_verified, 'the login is not verified yet');

        $this->travelMinutes(29);
        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertEquals($required_at, $this->freshLoginUser($login_user)->auth2fa_required_at, 'the start is not moved');

        // after the period: redirected to the 2factor page (setup), other pages cannot be used
        $this->travelMinutes(31);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
        $this->get(admin_url(''))->assertRedirect(admin_url('auth-2factor'));
        $this->get(admin_url('auth/setting/recovery-codes'))->assertRedirect(admin_url('auth-2factor'));

        // the 2factor pages themselves are open: the setup (send the registration mail) can be done
        $this->get(admin_url('auth-2factor'))->assertStatus(200);
        $this->get(admin_url('auth-2factor/recovery'))->assertStatus(200);
    }

    /**
     * Grace minutes 0: the setup is required right after login.
     */
    public function testGraceMinutesZeroRedirectsAtOnce(): void
    {
        $this->useRoleGroupTarget(0);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
    }

    /**
     * The grace period is not reset by logging in again: it is counted from the first login.
     */
    public function testGracePeriodIsNotResetByReLogin(): void
    {
        $this->useRoleGroupTarget(30);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertStatus(200);

        $this->travelMinutes(31);
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
    }

    /**
     * Target "all" (as before): a user who has not registered the Google authenticator is redirected at once. No grace period.
     */
    public function testAllTargetHasNoGracePeriod(): void
    {
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
        $this->assertNull($this->freshLoginUser($login_user)->auth2fa_required_at);
    }

    /**
     * The start of the grace period is forgotten when the user is not required any more,
     * so that the user gets the full period again if required later.
     */
    public function testGraceStartIsClearedWhenNotRequiredAnymore(): void
    {
        $this->useRoleGroupTarget(30);
        $role_group = $this->createRoleGroup(true);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($role_group, $login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertNotNull($this->freshLoginUser($login_user)->auth2fa_required_at);

        $role_group->require_2factor = false;
        $role_group->save();

        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertNull($this->freshLoginUser($login_user)->auth2fa_required_at);
    }

    /**
     * A user who has registered the Google authenticator is asked the one-time password at once (no grace period),
     * and after the correct one-time password the page opens.
     */
    public function testRequiredUserWithSetupIsAskedOneTimePassword(): void
    {
        $this->useRoleGroupTarget(30);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);
        $secret = $this->setUpGoogle($login_user, true);
        Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
        $this->assertNull($this->freshLoginUser($login_user)->auth2fa_required_at);

        $this->postVerify(['verify_code' => '000000'])->assertSessionHasErrors('verify_code');

        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertTrue($this->historiesOf($login_user)[0]->fresh()->auth_2factor_verified);
    }

    /**
     * SSO logins mark the session as verified right after login (AuthOAuthController / AuthSamlController), so nothing changes for them.
     */
    public function testVerifiedSessionIsNotAskedAgain(): void
    {
        $this->useRoleGroupTarget(0);
        $login_user = $this->createLoginUser(LoginType::OAUTH, 'google');
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->loginAs($login_user);
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

        $this->get(admin_url('auth/setting'))->assertStatus(200);
    }

    // ------------------------------------------------------------------ //
    //  Recovery codes: issued at the setup                                 //
    // ------------------------------------------------------------------ //

    /**
     * Completing the Google setup (the first correct one-time password): the authenticator becomes available,
     * the grace period ends, 10 recovery codes are issued and shown once.
     */
    public function testSetupCompletionIssuesRecoveryCodes(): void
    {
        $this->useRoleGroupTarget(30);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);
        // the registration mail link was opened: the secret key exists but the authenticator is not verified yet
        $secret = $this->setUpGoogle($login_user, false);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertNotNull($this->freshLoginUser($login_user)->auth2fa_required_at, 'precondition: in the grace period');
        $this->assertFalse(Auth2factorRecoveryCodeService::hasCodes($login_user), 'precondition: no codes yet');

        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url('auth-2factor/recovery-codes'));

        $stored = $this->freshLoginUser($login_user);
        $this->assertTrue(boolval($stored->auth2fa_available));
        $this->assertNull($stored->auth2fa_required_at, 'the grace period is over');
        $this->assertTrue($this->historiesOf($login_user)[0]->fresh()->auth_2factor_verified);

        // shown once
        $response = $this->get(admin_url('auth-2factor/recovery-codes'));
        $response->assertStatus(200);
        $codes = $this->codesOnPage($response);
        $this->assertCount(Auth2factorRecoveryCodeService::CODE_COUNT, $codes);
        $this->assertCount(Auth2factorRecoveryCodeService::CODE_COUNT, array_unique($codes));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($login_user));

        // only the hash is stored, and a shown code is a valid code
        $this->assertCount(0, $this->codeRows($login_user)->filter(function ($row) use ($codes) {
            return in_array($row->code_hash, $codes) || in_array($row->code_hash, array_map(function ($c) {
                return str_replace('-', '', $c);
            }, $codes));
        }));
        $this->assertTrue(Auth2factorRecoveryCodeService::verify($login_user, $codes[0]));

        // the page cannot be shown again
        $this->get(admin_url('auth-2factor/recovery-codes'))->assertRedirect(admin_url(''));
        $this->get(admin_url('auth/setting'))->assertStatus(200);
    }

    /**
     * The e-mail provider has no setup: the codes are issued at the first login with the one-time password.
     */
    public function testEmailProviderIssuesRecoveryCodesAtFirstLogin(): void
    {
        System::login_2factor_provider(Login2FactorProviderType::EMAIL);
        $login_user = $this->createLoginUser();

        $this->postLogin($login_user)->assertStatus(302);
        $code = \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->where('login_user_id', $login_user->id)->value('verify_code');
        $this->assertNotNull($code, 'precondition: the one-time password must be issued');

        $this->postVerify(['verify_code' => $code])->assertRedirect(admin_url('auth-2factor/recovery-codes'));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($login_user));
        $this->get(admin_url('auth-2factor/recovery-codes'))->assertStatus(200);

        // from now on, the e-mail one-time password page links to the recovery code login
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth-2factor'))->assertStatus(200)->assertSee(admin_url('auth-2factor/recovery'));
    }

    /**
     * The codes are issued only once: the next login goes straight to the admin page, even if all the codes are used.
     */
    public function testRecoveryCodesAreIssuedOnlyOnce(): void
    {
        $login_user = $this->createLoginUser();
        $secret = $this->setUpGoogle($login_user, true);
        $codes = Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, $this->codeRows($login_user)->count());

        foreach ($codes as $code) {
            $this->assertTrue(Auth2factorRecoveryCodeService::verify($login_user, $code));
        }
        $this->assertSame(0, Auth2factorRecoveryCodeService::getRemainingCount($login_user));

        $this->postLogin($login_user)->assertStatus(302);
        $this->postVerify(['verify_code' => $this->otp($secret)])->assertRedirect(admin_url(''));
        $this->assertSame(0, Auth2factorRecoveryCodeService::getRemainingCount($login_user), 'not re-issued');
    }

    // ------------------------------------------------------------------ //
    //  Recovery codes: login                                               //
    // ------------------------------------------------------------------ //

    /**
     * Login with a recovery code instead of the one-time password: the code is consumed, the session and the login history are verified.
     */
    public function testLoginWithRecoveryCode(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        $codes = Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));

        // the one-time password page links to the recovery code page
        $this->get(admin_url('auth-2factor'))->assertStatus(200)->assertSee(admin_url('auth-2factor/recovery'));
        $this->get(admin_url('auth-2factor/recovery'))->assertStatus(200)->assertSee('name="recovery_code"', false);

        $this->postRecovery($codes[3])->assertRedirect(admin_url(''));

        // the admin page tells the remaining count instead of "logged in"
        $toastr = session('toastr');
        $this->assertNotNull($toastr);
        $this->assertSame(exmtrans('2factor.message.recovery_login_succeeded', Auth2factorRecoveryCodeService::CODE_COUNT - 1), $toastr->get('message')[0]);
        $this->assertSame('warning', $toastr->get('type')[0]);

        $this->assertTrue(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
        $this->get(admin_url('auth/setting'))->assertStatus(200);
        $this->assertTrue($this->historiesOf($login_user)[0]->fresh()->auth_2factor_verified);
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT - 1, Auth2factorRecoveryCodeService::getRemainingCount($login_user));
        $this->assertSame(1, $this->codeRows($login_user)->whereNotNull('used_at')->count(), 'the used code is kept as used');
    }

    /**
     * A used code, a wrong code, and a code of another user are rejected. Case, spaces and hyphens are ignored.
     */
    public function testRecoveryCodeIsOneTimeAndOwnedByTheUser(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        $codes = Auth2factorRecoveryCodeService::generate($login_user);

        $other = $this->createLoginUser();
        $otherCodes = Auth2factorRecoveryCodeService::generate($other);

        $this->postLogin($login_user)->assertStatus(302);

        $this->postRecovery('ABCDE-FGHJK')->assertSessionHasErrors('recovery_code');
        $this->postRecovery($otherCodes[0])->assertSessionHasErrors('recovery_code');
        $this->postRecovery('')->assertSessionHasErrors('recovery_code');
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($other), 'the code of the other user is not consumed');

        // lower case, no hyphen, spaces: accepted
        $relaxed = ' ' . strtolower(str_replace('-', '', $codes[0])) . ' ';
        $this->postRecovery($relaxed)->assertRedirect(admin_url(''));

        // the same code again: rejected
        $this->postLogin($login_user)->assertStatus(302);
        $this->postRecovery($codes[0])->assertSessionHasErrors('recovery_code');
        $this->postRecovery($codes[1])->assertRedirect(admin_url(''));
    }

    /**
     * Login with the last code: no new codes are issued by the recovery login (a stolen code must not give the other codes).
     */
    public function testRecoveryLoginDoesNotIssueNewCodes(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        $codes = Auth2factorRecoveryCodeService::generate($login_user);
        foreach (array_slice($codes, 1) as $code) {
            $this->assertTrue(Auth2factorRecoveryCodeService::verify($login_user, $code));
        }
        $this->assertSame(1, Auth2factorRecoveryCodeService::getRemainingCount($login_user));

        $this->postLogin($login_user)->assertStatus(302);
        $this->postRecovery($codes[0])->assertRedirect(admin_url(''));

        $this->assertSame(0, Auth2factorRecoveryCodeService::getRemainingCount($login_user));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, $this->codeRows($login_user)->count());

        // no usable code: the recovery page tells it instead of the form
        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth-2factor'))->assertStatus(200)->assertDontSee(admin_url('auth-2factor/recovery'));
        $this->get(admin_url('auth-2factor/recovery'))->assertStatus(200)
            ->assertSee(exmtrans('2factor.message.recovery_no_codes'))
            ->assertDontSee('name="recovery_code"', false);
    }

    /**
     * The recovery code form is throttled, per user and IP address.
     */
    public function testRecoveryCodeFormIsThrottled(): void
    {
        config(['exment.throttle' => true, 'exment.max_attempts' => 3]);
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        $codes = Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        for ($i = 0; $i < 3; $i++) {
            $this->postRecovery('ABCDE-FGHJK')->assertSessionHasErrors('recovery_code');
        }

        // locked: even the correct code is rejected (the lockout response is rendered by the Exment error page, as for the one-time password form)
        $response = $this->postRecovery($codes[0]);
        $this->assertNotEquals(admin_url(''), $response->headers->get('Location'), 'must not be logged in');
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($login_user), 'the code is not consumed');
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
    }

    // ------------------------------------------------------------------ //
    //  Recovery codes: user setting                                        //
    // ------------------------------------------------------------------ //

    /**
     * The user setting shows the remaining count and re-issues the codes: the old codes are invalidated and the new ones are shown once.
     */
    public function testUserSettingReissuesRecoveryCodes(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        $old = Auth2factorRecoveryCodeService::generate($login_user);
        $this->assertTrue(Auth2factorRecoveryCodeService::verify($login_user, $old[0]));

        $this->loginAs($login_user);
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

        $this->get(admin_url('auth/setting'))->assertStatus(200)
            ->assertSee(admin_url('auth/setting/recovery-codes'))
            ->assertSee(exmtrans('2factor.message.recovery_codes_remaining', 9, 10));

        $this->get(admin_url('auth/setting/recovery-codes'))->assertStatus(200)
            ->assertSee(exmtrans('2factor.regenerate_recovery_codes'));

        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth/setting/recovery-codes'))
            ->assertRedirect(admin_url('auth-2factor/recovery-codes'));

        $response = $this->get(admin_url('auth-2factor/recovery-codes'));
        $response->assertStatus(200);
        $new = $this->codesOnPage($response);
        $this->assertCount(Auth2factorRecoveryCodeService::CODE_COUNT, $new);
        $this->assertEmpty(array_intersect($old, $new));

        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, $this->codeRows($login_user)->count(), 'the old rows are deleted');
        $this->assertFalse(Auth2factorRecoveryCodeService::verify($login_user, $old[1]), 'the old code is invalid');
        $this->assertTrue(Auth2factorRecoveryCodeService::verify($login_user, $new[0]));
    }

    /**
     * A user who is not required 2factor has no recovery code section, and cannot issue codes.
     */
    public function testUserSettingHidesRecoveryCodesWhenNotRequired(): void
    {
        $this->useRoleGroupTarget();
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(false), $login_user);

        $this->loginAs($login_user);
        $this->get(admin_url('auth/setting'))->assertStatus(200)->assertDontSee(admin_url('auth/setting/recovery-codes'));
        $this->get(admin_url('auth/setting/recovery-codes'))->assertRedirect(admin_url('auth/setting'));
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth/setting/recovery-codes'))
            ->assertRedirect(admin_url('auth/setting'));
        $this->assertFalse(Auth2factorRecoveryCodeService::hasCodes($login_user));
    }

    // ------------------------------------------------------------------ //
    //  Settings screens                                                    //
    // ------------------------------------------------------------------ //

    /**
     * Login setting: the role group switch and the grace minutes are saved. An empty grace keeps the saved value,
     * out of range and a value that is not YES/NO are rejected.
     */
    public function testLoginSettingSavesTargetAndGrace(): void
    {
        $this->loginAsAdmin();
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

        $this->get(admin_url('login_setting'))->assertStatus(200)
            ->assertSee('name="login_2factor_target_role_group"', false)
            ->assertSee('name="login_2factor_setup_grace_minutes"', false)
            // the grace minutes are shown only while the switch is YES
            ->assertSee('login_2factor_target_role_group&quot;,&quot;value&quot;:&quot;1&quot;', false);

        $this->post2factorSetting([
            'login_2factor_target_role_group' => '1',
            'login_2factor_setup_grace_minutes' => 45,
        ])->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertTrue(System::login_2factor_target_role_group());
        $this->assertSame(45, System::login_2factor_setup_grace_minutes());

        // empty grace minutes: keeps 45 (must not become 0)
        $this->post2factorSetting([
            'login_2factor_target_role_group' => '1',
            'login_2factor_setup_grace_minutes' => '',
        ])->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertSame(45, System::login_2factor_setup_grace_minutes());

        // out of range / not YES or NO: rejected, nothing is saved
        $this->post2factorSetting([
            'login_2factor_target_role_group' => '1',
            'login_2factor_setup_grace_minutes' => Auth2factorService::SETUP_GRACE_MINUTES_MAX + 1,
        ])->assertSessionHasErrors('login_2factor_setup_grace_minutes');
        $this->post2factorSetting([
            'login_2factor_target_role_group' => 'everyone',
            'login_2factor_setup_grace_minutes' => 10,
        ])->assertSessionHasErrors('login_2factor_target_role_group');
        System::clearCache();
        $this->assertTrue(System::login_2factor_target_role_group());
        $this->assertSame(45, System::login_2factor_setup_grace_minutes());

        // back to NO (every user), grace 0
        $this->post2factorSetting([
            'login_2factor_target_role_group' => '0',
            'login_2factor_setup_grace_minutes' => 0,
        ])->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertFalse(System::login_2factor_target_role_group());
        $this->assertSame(0, System::login_2factor_setup_grace_minutes());
    }

    /**
     * Role group: the flag is on the form and the list only when 2factor is enabled, and is saved by the form.
     */
    public function testRoleGroupFormSavesTheFlag(): void
    {
        $this->loginAsAdmin();
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        $role_group = $this->createRoleGroup(false);

        $this->get(admin_urls('role_group', $role_group->id, 'edit'))->assertStatus(200)->assertSee('name="require_2factor"', false);
        $this->get(admin_url('role_group'))->assertStatus(200)->assertSee(exmtrans('role_group.require_2factor'));

        $this->putRoleGroup($role_group, ['require_2factor' => '1'])->assertRedirect(admin_url('role_group'));
        $this->assertTrue($this->freshRoleGroup($role_group)->require_2factor);

        $this->putRoleGroup($role_group, ['require_2factor' => '0'])->assertRedirect(admin_url('role_group'));
        $this->assertFalse($this->freshRoleGroup($role_group)->require_2factor);

        // 2factor disabled: the flag is hidden, and saving the form does not touch it
        $role_group->require_2factor = true;
        $role_group->save();
        config(['exment.login_use_2factor' => false]);
        $this->get(admin_urls('role_group', $role_group->id, 'edit'))->assertStatus(200)->assertDontSee('name="require_2factor"', false);
        $this->get(admin_url('role_group'))->assertStatus(200)->assertDontSee(exmtrans('role_group.require_2factor'));
        $this->putRoleGroup($role_group, ['require_2factor' => '0'])->assertRedirect(admin_url('role_group'));
        $this->assertTrue($this->freshRoleGroup($role_group)->require_2factor, 'kept');
    }

    // ------------------------------------------------------------------ //
    //  Login history                                                       //
    // ------------------------------------------------------------------ //

    /**
     * Login history: "2factor required" follows the user. SSO is never subject.
     */
    public function testLoginHistoryFollowsTheUser(): void
    {
        $this->useRoleGroupTarget();
        $required = $this->createLoginUser();
        $notRequired = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(false), $notRequired);

        System::clearRequestSession();
        $this->assertTrue(LoginHistoryService::requires2factor(LoginType::PURE, $required));
        $this->assertTrue(LoginHistoryService::requires2factor(LoginType::LDAP, $required));
        $this->assertFalse(LoginHistoryService::requires2factor(LoginType::OAUTH, $required));
        $this->assertFalse(LoginHistoryService::requires2factor(LoginType::SAML, $required));
        System::clearRequestSession();
        $this->assertFalse(LoginHistoryService::requires2factor(LoginType::PURE, $notRequired));
        $this->assertTrue(LoginHistoryService::requires2factor(LoginType::PURE), 'without the user: 2factor is enabled');

        System::clearRequestSession();
        request()->server->set('REMOTE_ADDR', self::IP);
        $this->assertFalse(LoginHistoryService::record($required)->auth_2factor_verified);
        System::clearRequestSession();
        $this->assertNull(LoginHistoryService::record($notRequired)->auth_2factor_verified);
    }

    // ------------------------------------------------------------------ //
    //  Hardening                                                           //
    // ------------------------------------------------------------------ //

    /**
     * A required user who has not set up 2factor (in the grace period) must not get recovery codes from the user setting,
     * and must not pass 2factor by a recovery code: otherwise the codes would replace the setup for good.
     */
    public function testUserWithoutSetupCannotGetOrUseRecoveryCodes(): void
    {
        $this->useRoleGroupTarget(30);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertStatus(200)->assertDontSee(admin_url('auth/setting/recovery-codes'));
        $this->get(admin_url('auth/setting/recovery-codes'))->assertRedirect(admin_url('auth/setting'));
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth/setting/recovery-codes'))
            ->assertRedirect(admin_url('auth/setting'));
        $this->assertFalse(Auth2factorRecoveryCodeService::hasCodes($login_user), 'no codes are issued in the grace period');

        // even if codes exist (whatever the way), they cannot be used before the setup
        $codes = Auth2factorRecoveryCodeService::generate($login_user);
        $this->get(admin_url('auth-2factor/recovery'))->assertStatus(200)->assertDontSee('name="recovery_code"', false);
        $this->postRecovery($codes[0])->assertSessionHasErrors('recovery_code');
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($login_user), 'not consumed');

        // after the grace period the user is still sent to the setup
        $this->travelMinutes(31);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
        $this->postRecovery($codes[1])->assertSessionHasErrors('recovery_code');
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
    }

    /**
     * Garbage input (an array) for the recovery code is a failed attempt, not a server error.
     */
    public function testRecoveryVerifyRejectsNonStringInput(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        Auth2factorRecoveryCodeService::generate($login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(admin_url('auth-2factor/recovery/verify'), ['recovery_code' => ['ABCDE-FGHJK']])
            ->assertSessionHasErrors('recovery_code');
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)));
    }

    /**
     * The lock of the recovery code form is per user: a locked user does not lock another user of the same IP address.
     */
    public function testRecoveryThrottleIsScopedPerUser(): void
    {
        config(['exment.throttle' => true, 'exment.max_attempts' => 3]);
        $locked = $this->createLoginUser();
        $this->setUpGoogle($locked, true);
        Auth2factorRecoveryCodeService::generate($locked);

        $other = $this->createLoginUser();
        $this->setUpGoogle($other, true);
        $otherCodes = Auth2factorRecoveryCodeService::generate($other);

        $this->postLogin($locked)->assertStatus(302);
        for ($i = 0; $i < 3; $i++) {
            $this->postRecovery('ABCDE-FGHJK')->assertSessionHasErrors('recovery_code');
        }

        $this->postLogin($other)->assertStatus(302);
        $this->postRecovery($otherCodes[0])->assertRedirect(admin_url(''));
    }

    /**
     * The role group switch is turned back to NO while a user is in the grace period: the setup is asked at once, as for everyone.
     */
    public function testTargetSwitchedToAllDuringGraceRedirectsAtOnce(): void
    {
        $this->useRoleGroupTarget(30);
        $login_user = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(true), $login_user);

        $this->postLogin($login_user)->assertStatus(302);
        $this->get(admin_url('auth/setting'))->assertStatus(200);

        System::login_2factor_target_role_group(false);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
    }

    /**
     * A POST of the login setting that lacks the role group switch (an old form, an API client) keeps the "every user" behavior.
     */
    public function testLoginSettingWithoutTargetKeepsAllUsers(): void
    {
        $this->loginAsAdmin();
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);

        $this->post2factorSetting(['login_2factor_setup_grace_minutes' => 20])->assertRedirect(admin_url('login_setting'));
        System::clearCache();
        $this->assertFalse(System::login_2factor_target_role_group());

        $inGroup = $this->createLoginUser();
        $this->addToRoleGroup($this->createRoleGroup(false), $inGroup);
        $this->assertTrue($this->isRequired($inGroup), 'everyone is required');
    }

    /**
     * The flag is a policy of each system: not exported with the role group template, and ignored when a template is imported.
     */
    public function testRoleGroupTemplateExcludesTheFlag(): void
    {
        $role_group = $this->createRoleGroup(true);

        $this->assertContains('require_2factor', RoleGroup::$templateItems['excepts']);
        $this->assertArrayNotHasKey('require_2factor', $role_group->getTemplateExportItems());

        // a template that has the key (ex. edited by hand): the flag is not imported
        $name = 'l2fa_tpl_' . short_uuid();
        $imported = RoleGroup::importTemplate([
            'role_group_name' => $name,
            'role_group_view_name' => $name,
            'role_group_order' => 0,
            'require_2factor' => true,
        ], false);
        $this->assertInstanceOf(RoleGroup::class, $imported);
        $this->assertFalse($this->freshRoleGroup($imported)->require_2factor, 'the flag of a new role group stays off');

        // importing over an existing role group keeps the flag set on this system
        RoleGroup::importTemplate([
            'role_group_name' => $role_group->role_group_name,
            'role_group_view_name' => $role_group->role_group_view_name . ' (imported)',
            'role_group_order' => 0,
            'require_2factor' => false,
        ], false);
        $fresh = $this->freshRoleGroup($role_group);
        $this->assertTrue($fresh->require_2factor, 'the flag of an existing role group is kept');
        $this->assertSame($role_group->role_group_view_name . ' (imported)', $fresh->role_group_view_name, 'precondition: the import did update the role group');
    }

    /**
     * Required through the organization follows the system setting "org_joined_type_role_group",
     * the same rule as the permissions of the role group: whether the role groups of the parent / child organizations
     * of the organization the user belongs to are inherited.
     */
    public function testRoleGroupTargetThroughOrganizationTree(): void
    {
        if (!System::organization_available()) {
            $this->markTestSkipped('organization is not available in this environment.');
        }
        $this->useRoleGroupTarget();
        $oldType = System::org_joined_type_role_group();

        try {
            // parent organization, child organization. "inChild" belongs to the child, "inParent" belongs to the parent.
            $inChild = $this->createLoginUser();
            $inParent = $this->createLoginUser();
            $parentOrg = $this->createOrganizationWithUser($inParent);
            $childOrg = $this->createOrganizationWithUser($inChild);
            $childOrg->setValue('parent_organization', $parentOrg->id);
            $childOrg->saved_notify(false);
            $childOrg->save();

            // both users are in a not required role group as a user, so "no role group" never applies
            $notRequired = $this->createRoleGroup(false);
            $this->addToRoleGroup($notRequired, $inChild);
            $this->addToRoleGroup($notRequired, $inParent);

            // the required role group is assigned to the PARENT organization
            $requiredOnParent = $this->createRoleGroup(true);
            $this->addOrganizationToRoleGroup($requiredOnParent, $parentOrg);

            // [setting => [user in the child org is required, user in the parent org is required]]
            $cases = [
                JoinedOrgFilterType::ONLY_JOIN => [false, true],
                JoinedOrgFilterType::ONLY_UPPER => [false, true],
                JoinedOrgFilterType::ONLY_DOWNER => [true, true],
                JoinedOrgFilterType::ALL => [true, true],
            ];
            foreach ($cases as $type => [$childRequired, $parentRequired]) {
                System::org_joined_type_role_group($type);
                System::clearCache();
                $this->assertSame($childRequired, $this->isRequired($inChild), "type {$type}: user of the child organization");
                $this->assertSame($parentRequired, $this->isRequired($inParent), "type {$type}: user of the parent organization");
            }

            // the required role group is assigned to the CHILD organization instead
            RoleGroupUserOrganization::query()->where('role_group_id', $requiredOnParent->id)->delete();
            $requiredOnChild = $this->createRoleGroup(true);
            $this->addOrganizationToRoleGroup($requiredOnChild, $childOrg);

            $cases = [
                JoinedOrgFilterType::ONLY_JOIN => [true, false],
                JoinedOrgFilterType::ONLY_UPPER => [true, true],
                JoinedOrgFilterType::ONLY_DOWNER => [true, false],
                JoinedOrgFilterType::ALL => [true, true],
            ];
            foreach ($cases as $type => [$childRequired, $parentRequired]) {
                System::org_joined_type_role_group($type);
                System::clearCache();
                $this->assertSame($childRequired, $this->isRequired($inChild), "type {$type}: user of the child organization");
                $this->assertSame($parentRequired, $this->isRequired($inParent), "type {$type}: user of the parent organization");
            }
        } finally {
            System::org_joined_type_role_group($oldType);
            System::clearCache();
        }
    }

    /**
     * The POSTs of the recovery codes are protected by CSRF: without the token nothing happens.
     */
    public function testRecoveryPostsRequireCsrfToken(): void
    {
        $login_user = $this->createLoginUser();
        $this->setUpGoogle($login_user, true);
        $codes = Auth2factorRecoveryCodeService::generate($login_user);

        // logged in (password accepted) but 2factor not verified. Not through postLogin(): withoutMiddleware() lasts for the whole test.
        $this->loginAs($login_user);
        $this->get(admin_url('auth/setting'))->assertRedirect(admin_url('auth-2factor'));
        $this->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post(admin_url('auth-2factor/recovery/verify'), ['recovery_code' => $codes[0]]);
        $this->assertFalse(boolval(session(Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR)), 'not verified without the token');
        $this->assertSame(Auth2factorRecoveryCodeService::CODE_COUNT, Auth2factorRecoveryCodeService::getRemainingCount($login_user), 'not consumed');

        $this->loginAs($login_user);
        session([Define::SYSTEM_KEY_SESSION_AUTH_2FACTOR => true]);
        $this->post(admin_url('auth/setting/recovery-codes'));
        $this->assertTrue(Auth2factorRecoveryCodeService::verify($login_user, $codes[0]), 'the codes are not replaced without the token');
    }

    // ------------------------------------------------------------------ //
    //  helpers                                                             //
    // ------------------------------------------------------------------ //

    /**
     * Turn the role group switch to YES: 2factor is required by the role groups.
     */
    protected function useRoleGroupTarget(int $graceMinutes = Auth2factorService::DEFAULT_SETUP_GRACE_MINUTES): void
    {
        System::login_2factor_target_role_group(true);
        System::login_2factor_setup_grace_minutes($graceMinutes);
    }

    /**
     * Whether the user is required 2factor. The role groups / organizations of a user are cached per request, so clear them first.
     */
    protected function isRequired(LoginUser $login_user): bool
    {
        System::clearRequestSession();
        return Auth2factorService::isRequiredFor($login_user);
    }

    protected function createRoleGroup(bool $require2factor): RoleGroup
    {
        $name = 'l2fa_role_' . short_uuid();
        $role_group = RoleGroup::create([
            'role_group_name' => $name,
            'role_group_view_name' => $name,
            'role_group_order' => 0,
            'require_2factor' => $require2factor,
        ]);
        System::clearCache();
        return $role_group;
    }

    protected function addToRoleGroup(RoleGroup $role_group, LoginUser $login_user): void
    {
        RoleGroupUserOrganization::create([
            'role_group_id' => $role_group->id,
            'role_group_user_org_type' => SystemTableName::USER,
            'role_group_target_id' => $login_user->base_user_id,
        ]);
        // role groups and the user's permissions are cached
        System::clearCache();
    }

    protected function addOrganizationToRoleGroup(RoleGroup $role_group, CustomValue $org): void
    {
        RoleGroupUserOrganization::create([
            'role_group_id' => $role_group->id,
            'role_group_user_org_type' => SystemTableName::ORGANIZATION,
            'role_group_target_id' => $org->id,
        ]);
        System::clearCache();
    }

    /**
     * Create an organization the user belongs to.
     */
    protected function createOrganizationWithUser(LoginUser $login_user): CustomValue
    {
        $code = 'l2fa_org_' . short_uuid();
        $org = CustomTable::getEloquent(SystemTableName::ORGANIZATION)->getValueModel();
        $org->setValue([
            'organization_code' => $code,
            'organization_name' => $code,
        ]);
        $org->saved_notify(false);
        $org->save();

        \DB::table(CustomRelation::getRelationNameByTables(SystemTableName::ORGANIZATION, SystemTableName::USER))->insert([
            'parent_id' => $org->id,
            'child_id' => $login_user->base_user_id,
        ]);
        System::clearCache();

        return $org;
    }

    /**
     * Create a user (custom value) and its login user. No role group is assigned.
     */
    protected function createLoginUser(string $login_type = LoginType::PURE, ?string $login_provider = null): LoginUser
    {
        $code = 'l2fa_' . short_uuid();

        $user = CustomTable::getEloquent(SystemTableName::USER)->getValueModel();
        $user->setValue([
            'user_code' => $code,
            'user_name' => $code . '_name',
            'email' => $code . '@example.com',
        ]);
        $user->saved_notify(false);
        $user->save();

        $login_user = new LoginUser();
        $login_user->base_user_id = $user->id;
        $login_user->login_type = $login_type;
        $login_user->login_provider = $login_provider;
        $login_user->password = self::PASSWORD;
        $login_user->save();

        $login_user = $this->freshLoginUser($login_user);
        $this->assertInstanceOf(LoginUser::class, $login_user);
        return $login_user;
    }

    /**
     * Register the Google authenticator of the user.
     *
     * @param bool $available true: the setup is completed (verified once). false: the secret key exists, but not verified yet.
     * @return string the secret key
     */
    protected function setUpGoogle(LoginUser $login_user, bool $available): string
    {
        $secret = (new Google2FA())->generateSecretKey();
        LoginUser::query()->where('id', $login_user->id)->update([
            'auth2fa_key' => encrypt($secret),
            'auth2fa_available' => $available,
        ]);
        return $secret;
    }

    protected function otp(string $secret): string
    {
        return (new Google2FA())->getCurrentOtp($secret);
    }

    protected function travelMinutes(int $minutes): void
    {
        Carbon::setTestNow(Carbon::now()->addMinutes($minutes));
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postLogin(LoginUser $login_user): TestResponse
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->flushSession();

        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post(admin_url('auth/login'), [
                'username' => $login_user->user_code,
                'password' => self::PASSWORD,
            ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postVerify(array $data): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post(admin_url('auth-2factor/verify'), $data);
    }

    /**
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postRecovery(string $code): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->withServerVariables(['REMOTE_ADDR' => self::IP])
            ->post(admin_url('auth-2factor/recovery/verify'), ['recovery_code' => $code]);
    }

    /**
     * Post the 2factor box of the login setting, as the administrator. The box asks a verification code sent to the administrator.
     *
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function post2factorSetting(array $data): TestResponse
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $code = (string)random_int(100000, 999999);
        \DB::table(SystemTableName::EMAIL_CODE_VERIFY)->insert([
            'login_user_id' => $admin->id,
            'email' => $admin->email,
            'verify_code' => $code,
            'verify_type' => '2factor_system',
            'valid_period_datetime' => Carbon::now()->addMinutes(60)->format('Y/m/d H:i'),
        ]);

        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->post(route('exment.post2factor'), array_merge([
                'login_use_2factor' => '1',
                'login_2factor_provider' => Login2FactorProviderType::GOOGLE,
                'login_2factor_verify_code' => $code,
            ], $data));
    }

    /**
     * @param array<string, mixed> $data
     * @return TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function putRoleGroup(RoleGroup $role_group, array $data): TestResponse
    {
        return $this->withoutMiddleware(VerifyCsrfToken::class)
            ->put(admin_urls('role_group', $role_group->id), array_merge([
                'role_group_view_name' => $role_group->role_group_view_name,
                'role_group_order' => 0,
            ], $data));
    }

    /**
     * Re-read the login user from the database.
     */
    protected function freshLoginUser(LoginUser $login_user): LoginUser
    {
        $fresh = LoginUser::query()->where('id', $login_user->id)->first();
        $this->assertInstanceOf(LoginUser::class, $fresh);
        return $fresh;
    }

    /**
     * Re-read the role group from the database.
     */
    protected function freshRoleGroup(RoleGroup $role_group): RoleGroup
    {
        $fresh = RoleGroup::query()->where('id', $role_group->id)->first();
        $this->assertInstanceOf(RoleGroup::class, $fresh);
        return $fresh;
    }

    /**
     * Log in without the login form. The user is re-read from the database, as the guard does on a real request
     * (the tests update auth2fa_* columns directly, so the instance in hand may be stale).
     */
    protected function loginAs(LoginUser $login_user): void
    {
        app('auth')->forgetGuards();
        System::clearRequestSession();
        $this->be($this->freshLoginUser($login_user), Define::AUTHENTICATE_KEY_WEB);
    }

    protected function loginAsAdmin(): void
    {
        $admin = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->assertInstanceOf(LoginUser::class, $admin);
        $this->loginAs($admin);
    }

    /**
     * The recovery codes shown on the page, in order.
     *
     * @param TestResponse<\Symfony\Component\HttpFoundation\Response> $response
     * @return array<string>
     */
    protected function codesOnPage(TestResponse $response): array
    {
        preg_match_all('/[A-Z2-9]{5}-[A-Z2-9]{5}/', (string)$response->getContent(), $matches);
        return array_values(array_unique($matches[0]));
    }

    /**
     * @return \Illuminate\Support\Collection<int, Auth2factorRecoveryCode>
     */
    protected function codeRows(LoginUser $login_user): \Illuminate\Support\Collection
    {
        return Auth2factorRecoveryCode::query()->where('login_user_id', $login_user->id)->get()->toBase();
    }

    protected function countEmailCodes(LoginUser $login_user): int
    {
        return \DB::table(SystemTableName::EMAIL_CODE_VERIFY)
            ->where('login_user_id', $login_user->id)
            ->where('verify_type', '2factor_' . Login2FactorProviderType::EMAIL)
            ->count();
    }

    /**
     * @return \Illuminate\Support\Collection<int, LoginHistory>
     */
    protected function historiesOf(LoginUser $login_user): \Illuminate\Support\Collection
    {
        return LoginHistory::query()->where('base_user_id', $login_user->base_user_id)->orderBy('id')->get()->values()->toBase();
    }
}
