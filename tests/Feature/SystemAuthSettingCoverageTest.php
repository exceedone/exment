<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;

/**
 * Requests no test sent before to the system setting screen (admin/system), the user's own
 * setting screen (admin/auth/setting), the logout, the LINE unlink and the installer.
 *
 * Files uploaded here are stored on the admin disk, outside the database transaction: each test
 * deletes them through the screen under test, and tearDown() removes any a failed test left.
 */
class SystemAuthSettingCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    /**
     * A 1x1 PNG: the image fields validate an upload as an image.
     */
    private const PNG_1PX = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    /**
     * @var list<string> paths on the admin disk that the test stored
     */
    private array $storedFiles = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    protected function tearDown(): void
    {
        foreach ($this->storedFiles as $path) {
            Storage::disk(config('admin.upload.disk'))->delete($path);
        }
        System::clearCache();
        parent::tearDown();
    }

    // SystemController (admin/system) ---------------------------------------------

    public function testTheBasicSystemSettingsAreSavedAndTheLogoDeleted(): void
    {
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($admin);
        // saving a logo deletes the previous file from the disk, which the rollback cannot bring back
        $this->assertNull($this->systemValue('site_logo'), 'the test data has a site logo');

        // the screen posts every basic setting: send the current ones, a new site name and a logo
        $input = [];
        foreach (System::get_system_values(['initialize']) as $key => $value) {
            if (in_array($key, ['site_logo', 'site_logo_mini', 'site_favicon'], true)) {
                continue;
            }
            $input[$key] = is_bool($value) ? ($value ? '1' : '0') : $value;
        }
        // the screen requires at least one system administrator
        $admin_users = System::system_admin_users();
        if (empty($admin_users)) {
            $admin_users = [(string) $admin->base_user_id];
        }
        $input['site_name'] = 'Claude coverage site';
        $input['site_logo'] = UploadedFile::fake()->createWithContent('claude-coverage-logo.png', (string) base64_decode(self::PNG_1PX));
        $input['system_admin_users'] = $admin_users;

        $this->post(admin_url('system'), $input)
            ->assertRedirect(admin_url('system'))
            ->assertSessionHasNoErrors();

        System::clearCache();
        $this->assertSame('Claude coverage site', System::site_name());
        $this->assertEquals($admin_users, System::system_admin_users());
        $logo = $this->systemValue('site_logo');
        $this->assertNotNull($logo);
        $this->storedFiles[] = (string) $logo;
        $this->assertTrue(Storage::disk(config('admin.upload.disk'))->exists((string) $logo));

        // the delete button of the logo field
        $this->put(admin_url('system/filedelete'), ['delete_flg' => 'site_logo'])
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.delete_succeeded')]);

        $this->assertNull($this->systemValue('site_logo'));
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists((string) $logo));
    }

    public function testATestMailIsSent(): void
    {
        Notification::fake();
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->post(admin_url('system/send_testmail'), ['test_mail_to' => 'claude-coverage@example.com'])
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('common.message.sendmail_succeeded')]);
        Notification::assertSentTimes(MailSendJob::class, 1);

        // an address that is not one is refused before anything is sent
        $this->post(admin_url('system/send_testmail'), ['test_mail_to' => 'not-an-address'])
            ->assertStatus(400)
            ->assertJson(['result' => false]);
        Notification::assertSentTimes(MailSendJob::class, 1);
    }

    public function testTheUpdateIsRefusedToAUserWithoutTheSystemPermission(): void
    {
        // SystemController::callUpdate() puts the site in maintenance and runs composer to update
        // exment, which no test may do; what can be checked is that only a system administrator
        // reaches it. An ajax request gets the denial as a 403 (a page request would exit()).
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2));

        $this->withHeaders(['X-Requested-With' => 'XMLHttpRequest'])
            ->post(admin_url('system/call_update'))
            ->assertForbidden();

        $this->assertFalse($this->app->isDownForMaintenance());
    }

    // AuthController (admin/auth/setting, admin/auth/logout) ----------------------

    public function testTheUserSettingSavesAnAvatarAndDeletesIt(): void
    {
        $admin = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $this->be($admin);
        // the delete below removes the file from the disk, which the rollback cannot bring back
        $this->assertNull($admin->avatar, 'the test admin has an avatar');

        $this->put(admin_url('auth/setting'), [
            'avatar' => UploadedFile::fake()->createWithContent('claude-coverage-avatar.png', (string) base64_decode(self::PNG_1PX)),
        ])->assertRedirect(admin_url('auth/setting'))->assertSessionHasNoErrors();

        $saved = LoginUser::query()->whereKey($admin->id)->firstOrFail();
        $avatar = $saved->avatar;
        $this->assertNotNull($avatar);
        $this->storedFiles[] = (string) $avatar;
        $this->assertTrue(Storage::disk(config('admin.upload.disk'))->exists((string) $avatar));

        // the guard keeps the user given to be() for every request of a test, while a real request
        // reads the logged-in user again: log in with the saved record, which has the avatar
        $this->be($saved);

        // the delete button of the avatar field
        $this->put(admin_url('auth/setting/filedelete'), ['delete_flg' => 'avatar'])
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.delete_succeeded')]);

        $this->assertNull(LoginUser::query()->whereKey($admin->id)->firstOrFail()->avatar);
        $this->assertFalse(Storage::disk(config('admin.upload.disk'))->exists((string) $avatar));
    }

    public function testTheLogoutEndsTheSessionAndOpensTheLoginPage(): void
    {
        $this->be(LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $this->get(admin_url('auth/logout'))->assertRedirect(admin_url('auth/login'));

        $this->assertGuest('admin');
    }

    // LineLinkController (admin/line/link) ----------------------------------------

    public function testTheLineUnlinkForgetsTheLinkedAccount(): void
    {
        $user = LoginUser::findOrFail(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $link = LineAccountLink::forUser((int) $user->base_user_id);
        $link->line_user_id = 'Uclaudecoverage00000000000000001';
        $link->linked_at = now();
        $link->save();
        $this->be($user, 'admin');

        $this->post(admin_url('line/link/unlink'))->assertRedirect(admin_url('line/link'));

        $link = LineAccountLink::where('user_id', $user->base_user_id)->firstOrFail();
        $this->assertFalse($link->isLinked());
        $this->assertNull($link->linked_at);
    }

    // InitializeController / InstallController ------------------------------------

    public function testTheInstallerRefusesToRunAgainOnceInstalled(): void
    {
        // the Initialize middleware sends every installer request of an installed system to the
        // login page before a controller runs, so nothing below creates a user or touches .env
        $values = [
            'site_name' => 'Claude coverage',
            'user_code' => 'claude_cov_installer',
            'user_name' => 'Claude coverage installer',
            'email' => 'claude-cov-installer@example.com',
            'password' => 'Coverage-Passw0rd1',
            'password_confirmation' => 'Coverage-Passw0rd1',
        ];

        $this->post(admin_url('initialize'), $values)->assertRedirect(admin_url('auth/login'));
        $this->post(admin_url('install'), $values)->assertRedirect(admin_url('auth/login'));

        $this->assertGuest('admin');
        $this->assertDatabaseMissing(getDBTableName(SystemTableName::USER), ['value->user_code' => 'claude_cov_installer']);
        System::clearCache();
        $this->assertTrue((bool) System::initialized());
    }

    private function systemValue(string $name): ?string
    {
        $value = DB::table((new System())->getTable())->where('system_name', $name)->value('system_value');

        return is_null($value) ? null : (string) $value;
    }
}
