<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Tests for LogController::postSetting
 * Covers validation and save behaviour of operation-log auto-delete settings.
 */
class LogControllerSettingTest extends FeatureTestBase
{
    use TestTrait;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    // ------------------------------------------------------------------ //
    //  Valid inputs                                                        //
    // ------------------------------------------------------------------ //

    /**
     * Saving valid keep_days (e.g. 180) with auto-delete enabled
     * ↁEredirect back with success, settings persisted.
     *
     * @return void
     */
    public function testPostSettingValidKeepDays(): void
    {
        $response = $this->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '180',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $this->assertEquals(true, boolval(System::operation_log_enable_automatic()));
        $this->assertEquals(180, (int) System::operation_log_keep_days());
    }

    /**
     * Saving with auto-delete disabled (checkbox unchecked ↁEnot sent)
     * ↁEredirect back with success, enabled flag set to false.
     *
     * @return void
     */
    public function testPostSettingDisabledAutoDelete(): void
    {
        $response = $this->post(admin_urls('auth', 'logs', 'setting'), [
            // 'operation_log_enable_automatic' not sent (checkbox unchecked)
            'operation_log_keep_days' => '90',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $this->assertEquals(false, boolval(System::operation_log_enable_automatic()));
    }

    /**
     * Minimum valid keep_days value is 1.
     *
     * @return void
     */
    public function testPostSettingKeepDaysOne(): void
    {
        $response = $this->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '1',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $this->assertEquals(1, (int) System::operation_log_keep_days());
    }

    // ------------------------------------------------------------------ //
    //  Invalid inputs  Eexpect redirect back with error toaster           //
    // ------------------------------------------------------------------ //

    /**
     * Submitting keep_days = 0 should NOT save and should redirect BACK
     * (preserving old input in session) with an error toastr,
     * not throw a validation exception page.
     *
     * @return void
     */
    public function testPostSettingKeepDaysZeroReturnsError(): void
    {
        // Pre-set a known good value so we can confirm it was NOT overwritten
        System::operation_log_keep_days(60);

        $response = $this->from(admin_url('auth/logs'))->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '0',
        ]);

        // Must redirect back (back()->withInput()), not to an error page
        $response->assertRedirect(admin_url('auth/logs'));

        // Old input must be flashed so form can repopulate
        $response->assertSessionHasInput('operation_log_enable_automatic');
        $response->assertSessionHasInput('operation_log_keep_days');

        // The previously stored value must remain unchanged
        $this->assertEquals(60, (int) System::operation_log_keep_days());
    }

    /**
     * Submitting a negative keep_days should behave the same as 0.
     *
     * @return void
     */
    public function testPostSettingKeepDaysNegativeReturnsError(): void
    {
        System::operation_log_keep_days(60);

        $response = $this->from(admin_url('auth/logs'))->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '-5',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $response->assertSessionHasInput('operation_log_enable_automatic');
        $this->assertEquals(60, (int) System::operation_log_keep_days());
    }

    /**
     * Submitting an empty keep_days while auto-delete is enabled should
     * redirect back with old input, not save.
     *
     * @return void
     */
    public function testPostSettingKeepDaysEmptyWithAutoEnabledReturnsError(): void
    {
        System::operation_log_keep_days(60);

        $response = $this->from(admin_url('auth/logs'))->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $response->assertSessionHasInput('operation_log_enable_automatic');
        $this->assertEquals(60, (int) System::operation_log_keep_days());
    }

    /**
     * Submitting a non-numeric string for keep_days should redirect back with error.
     *
     * @return void
     */
    public function testPostSettingKeepDaysNonNumericReturnsError(): void
    {
        System::operation_log_keep_days(60);

        $response = $this->from(admin_url('auth/logs'))->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => 'abc',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $response->assertSessionHasInput('operation_log_enable_automatic');
        $this->assertEquals(60, (int) System::operation_log_keep_days());
    }

    // ------------------------------------------------------------------ //
    //  Schedule fields (week / month / day / hour / minute)               //
    // ------------------------------------------------------------------ //

    /**
     * All five schedule fields are persisted correctly when valid values are sent.
     *
     * @return void
     */
    public function testPostSettingScheduleFieldsSaved(): void
    {
        $response = $this->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '180',
            'operation_log_automatic_week'   => '1',   // 月曜日
            'operation_log_automatic_month'  => '8',   // 8月
            'operation_log_automatic_day'    => '15',
            'operation_log_automatic_hour'   => '3',
            'operation_log_automatic_minute' => '30',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $this->assertEquals('1',  System::operation_log_automatic_week());
        $this->assertEquals('8',  System::operation_log_automatic_month());
        $this->assertEquals('15', System::operation_log_automatic_day());
        $this->assertEquals('3',  System::operation_log_automatic_hour());
        $this->assertEquals('30', System::operation_log_automatic_minute());
    }

    /**
     * Sending empty strings for schedule fields (i.e. user chose すべて)
     * must store null — not an empty string or "0".
     *
     * @return void
     */
    public function testPostSettingScheduleFieldsNullWhenEmpty(): void
    {
        // Pre-set values so we can confirm they are cleared
        System::operation_log_automatic_week('1');
        System::operation_log_automatic_hour('3');

        $response = $this->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '180',
            'operation_log_automatic_week'   => '',
            'operation_log_automatic_month'  => '',
            'operation_log_automatic_day'    => '',
            'operation_log_automatic_hour'   => '',
            'operation_log_automatic_minute' => '',
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $this->assertNull(System::operation_log_automatic_week());
        $this->assertNull(System::operation_log_automatic_month());
        $this->assertNull(System::operation_log_automatic_day());
        $this->assertNull(System::operation_log_automatic_hour());
        $this->assertNull(System::operation_log_automatic_minute());
    }

    /**
     * Schedule fields omitted from the request (not posted at all)
     * must also be stored as null.
     *
     * @return void
     */
    public function testPostSettingScheduleFieldsNullWhenNotSent(): void
    {
        // Pre-set a value so we can confirm it is cleared
        System::operation_log_automatic_week('3');

        $response = $this->post(admin_urls('auth', 'logs', 'setting'), [
            'operation_log_enable_automatic' => '1',
            'operation_log_keep_days'        => '180',
            // schedule fields intentionally omitted
        ]);

        $response->assertRedirect(admin_url('auth/logs'));
        $this->assertNull(System::operation_log_automatic_week());
        $this->assertNull(System::operation_log_automatic_month());
        $this->assertNull(System::operation_log_automatic_day());
        $this->assertNull(System::operation_log_automatic_hour());
        $this->assertNull(System::operation_log_automatic_minute());
    }
}
