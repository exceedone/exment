<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Providers\LoginUserProvider;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Regression tests for a login account that outlives its user record.
 *
 * An administrator deactivates somebody by deleting their "user" custom value. That delete is
 * a soft delete and it does NOT touch login_users, so a session opened before the delete stays
 * authenticated with LoginUser::base_user resolving to null.
 *
 * Everything that user then opens goes through resources/views/vendor/admin/partials/header.blade.php,
 * which calls Admin::user()->getHeaderInfo(). While that method dereferenced base_user directly, the
 * whole back office answered 500 for that account - dashboard, data grids, data detail, settings, and
 * the workflow task list alike. The two branches failed differently and both had to be covered:
 *
 *   header_user_info = [created_at]        -> Warning "Attempt to read property "created_at" on null",
 *                                             promoted to ErrorException by Laravel's error handler.
 *   header_user_info = [created_at, <col>] -> fatal Error "Call to a member function getValue() on null".
 *
 * REQUIRES the Exment test dataset:
 *     php artisan exment:inittest        <-- WARNING: this resets ALL data in the database
 *
 * The behaviour tests skip themselves when the dataset is absent. testHeaderPartialOnlyUsesNullSafeUserCalls()
 * reads a file and always runs.
 */
class LoginUserHeaderInfoTest extends UnitTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    /**
     * Expressions the header partial is allowed to call on Admin::user().
     * Each one is proven null-safe by testHeaderInfoOfUserWithoutBaseUser() below.
     *
     * @var array<string>
     */
    private const HEADER_USER_CALLS = ['display_avatar', 'name', 'getHeaderInfo', 'visible'];

    private const HEADER_PARTIAL = 'resources/views/vendor/admin/partials/header.blade.php';

    /**
     * @return LoginUser
     */
    protected function init()
    {
        $login_user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        if (!isset($login_user) || !isset($login_user->base_user)) {
            // the Exment test dataset is not installed on this database
            $this->markTestSkipped('run "php artisan exment:inittest" first (WARNING: it resets all data)');
        }

        $this->initAllTest();
        $this->be($login_user);

        return $login_user;
    }

    /**
     * Soft-delete the base user of $login_user and hand back a instance whose base_user is null,
     * exactly like a request served from a session opened before the delete.
     *
     * @param LoginUser $login_user
     * @return LoginUser
     */
    private function softDeleteBaseUser(LoginUser $login_user): LoginUser
    {
        $login_user->base_user->delete();

        /** @var LoginUser $reloaded */
        $reloaded = LoginUser::find($login_user->id);
        $reloaded->unsetRelation('base_user');

        $this->assertNull(
            $reloaded->base_user,
            'the soft-deleted user record must not resolve through the base_user relation'
        );

        return $reloaded;
    }

    /**
     * Run $callback with header_user_info temporarily set to $setting.
     *
     * @param array<string> $setting
     * @param callable $callback
     * @return mixed
     */
    private function withHeaderSetting(array $setting, callable $callback)
    {
        $original = System::header_user_info();

        System::header_user_info($setting);
        System::clearCache();

        try {
            return $callback();
        } finally {
            System::header_user_info($original);
            System::clearCache();
        }
    }

    /**
     * The default setting, [created_at], used to raise a warning that Laravel turns into a 500.
     *
     * @return void
     */
    public function testHeaderInfoWithCreatedAtAndDeletedBaseUser()
    {
        $login_user = $this->softDeleteBaseUser($this->init());

        $header = $this->withHeaderSetting(['created_at'], function () use ($login_user) {
            return $login_user->getHeaderInfo();
        });

        $this->assertSame('', $header, 'a user without a base record has no header info to show');
    }

    /**
     * The other branch is worse: it calls a method on null, so it is a fatal Error and not even
     * a warning. Any site that puts a user column in the header hit this one.
     *
     * @return void
     */
    public function testHeaderInfoWithUserColumnAndDeletedBaseUser()
    {
        $login_user = $this->softDeleteBaseUser($this->init());

        $column = CustomColumn::getEloquent('user_name', SystemTableName::USER);
        $this->assertNotNull($column, 'the user table must have a user_name column');

        // created_at is left out on purpose: it fails first and would mask this branch.
        $header = $this->withHeaderSetting([(string)$column->id], function () use ($login_user) {
            return $login_user->getHeaderInfo();
        });

        $this->assertSame('', $header, 'a user without a base record has no header info to show');
    }

    /**
     * The guard must not blank the header for everybody else: a live user still gets their line.
     *
     * @return void
     */
    public function testHeaderInfoOfLiveUserIsUnchanged()
    {
        $login_user = $this->init();

        $header = $this->withHeaderSetting(['created_at'], function () use ($login_user) {
            return $login_user->getHeaderInfo();
        });

        $this->assertNotSame('', $header, 'a live user must still get header info');
        $this->assertStringContainsString(
            (string)$login_user->base_user->created_at,
            $header,
            'the created_at line must still be rendered for a live user'
        );
    }

    /**
     * Every expression the header partial calls on Admin::user() must survive a null base_user,
     * otherwise the account loses the whole back office instead of just the header text.
     *
     * @return void
     */
    public function testHeaderInfoOfUserWithoutBaseUser()
    {
        $login_user = $this->softDeleteBaseUser($this->init());

        $this->assertSame('', $login_user->getHeaderInfo());
        $this->assertNull($login_user->name);
        $this->assertNotEmpty($login_user->display_avatar, 'the default avatar must be used as a fallback');
        $this->assertIsBool($login_user->visible('auth/setting'));
    }

    /**
     * Pin the partial against a new, unguarded call being added to it. If this fails, either make the
     * new expression null-safe and add it to HEADER_USER_CALLS, or move it out of the shared header.
     *
     * @return void
     */
    public function testHeaderPartialOnlyUsesNullSafeUserCalls()
    {
        $path = dirname(__DIR__, 2) . '/' . self::HEADER_PARTIAL;
        $this->assertFileExists($path);

        $content = file_get_contents($path);
        $this->assertNotFalse($content);

        preg_match_all('/Admin::user\(\)\s*->\s*([A-Za-z0-9_]+)/', $content, $matches);
        $this->assertNotEmpty($matches[1], 'the header partial is expected to read the current user');

        $unknown = array_values(array_diff(array_unique($matches[1]), self::HEADER_USER_CALLS));
        $this->assertSame(
            [],
            $unknown,
            'the header renders on every admin screen: ' . implode(', ', $unknown)
                . ' must be proven null-safe for a user whose base record was deleted, then listed in HEADER_USER_CALLS'
        );
    }

    /**
     * Deleting the user record is how an account is deactivated, so signing in again must fail.
     * If this ever starts returning a login user, the soft delete has stopped revoking access.
     *
     * @return void
     */
    public function testLoginIsRefusedAfterBaseUserIsDeleted()
    {
        $login_user = $this->init();
        $email = $login_user->base_user->getValue('email', true);
        $this->assertNotEmpty($email, 'the test user must have an email to sign in with');

        $this->assertNotNull(
            LoginUserProvider::findByCredential(['username' => $email]),
            'sanity check: the account is findable while the user record is alive'
        );

        $this->softDeleteBaseUser($login_user);

        $this->assertNull(
            LoginUserProvider::findByCredential(['username' => $email]),
            'an account whose user record was deleted must no longer be findable at sign-in'
        );
    }
}
