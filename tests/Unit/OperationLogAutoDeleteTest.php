<?php

namespace Exceedone\Exment\Tests\Unit;

use Tests\TestCase;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Console\ScheduleCommand;
use Exceedone\Exment\Model\Define;
use Carbon\Carbon;

/**
 * Guard tests for bug class (2): scheduled operation-log auto-delete.
 *
 * Two regressions are locked down here:
 *  (a) Opt-in default — operation_log_enable_automatic must default to '0' like
 *      backup_enable_automatic, so a destructive housekeeping task never purges audit
 *      logs on a fresh install without an explicit admin choice.
 *  (b) Run-once guard — ScheduleCommand wrote operation_log_automatic_executed but never
 *      read it, so with the default hourly scheduler the purge ran on every tick. The
 *      decision is now centralised in the pure ScheduleCommand::isOperationLogClearDue(),
 *      which must skip when it already ran today.
 *
 * isOperationLogClearDue() is a pure function (no DB, no deletion), so these tests are
 * deterministic and never touch real operation_log rows.
 */
class OperationLogAutoDeleteTest extends TestCase
{
    use TestTrait;

    /** A fixed reference instant: 2026-06-25 03:30:00. */
    private function now(): Carbon
    {
        // new Carbon(...) is typed as Carbon (Carbon::create() is Carbon|false and trips phpstan)
        return new Carbon('2026-06-25 03:30:00');
    }

    /**
     * (a) The destructive feature must ship opt-in, consistent with backup.
     *
     * @return void
     */
    public function testAutoDeleteShipsOptInLikeBackup()
    {
        $settings = Define::SYSTEM_SETTING_NAME_VALUE;

        $this->assertSame(
            '0',
            $settings['operation_log_enable_automatic']['default'],
            'operation_log auto-delete must default to opt-in ("0"); enabling a log purge by default silently deletes audit logs.'
        );
        $this->assertSame(
            $settings['backup_enable_automatic']['default'],
            $settings['operation_log_enable_automatic']['default'],
            'operation_log_enable_automatic default must match backup_enable_automatic (both opt-in).'
        );
    }

    /**
     * (b1) Disabled / invalid retention never runs.
     *
     * @return void
     */
    public function testDoesNotRunWhenDisabledOrRetentionInvalid()
    {
        $now = $this->now();

        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(false, 180, null, null, null, null, null, null, $now),
            'Disabled feature must not run.'
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 0, null, null, null, null, null, null, $now),
            'keep_days <= 0 must not run (would delete everything).'
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, null, null, null, null, null, null, null, $now),
            'empty keep_days must not run.'
        );
    }

    /**
     * (b2) Enabled with no time conditions runs once, then is blocked the rest of the day.
     *
     * @return void
     */
    public function testRunOnceGuardBlocksRepeatTicksSameDay()
    {
        $now = $this->now();

        // Never run today yet -> due.
        $this->assertTrue(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, null, null, null, $now),
            'Should run when enabled, retention valid, conditions empty and not yet executed today.'
        );

        // Already executed earlier today -> guard blocks (the core regression).
        $executedEarlierToday = $now->copy()->setTime(0, 0, 0);
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, null, null, $executedEarlierToday, $now),
            'Must NOT run again the same day after it already ran (hourly scheduler would otherwise purge 24x/day).'
        );

        // Executed yesterday -> due again.
        $executedYesterday = $now->copy()->subDay();
        $this->assertTrue(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, null, null, $executedYesterday, $now),
            'Should run again on a new calendar day.'
        );
    }

    /**
     * (b3) Hour condition is honored, including hour 0 (is_nullorempty("0") === false).
     *
     * @return void
     */
    public function testHourConditionIncludingZero()
    {
        $now = $this->now(); // hour = 3

        $this->assertTrue(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, '3', null, null, $now),
            'Matching hour should run.'
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, '5', null, null, $now),
            'Non-matching hour should not run.'
        );

        // hour = 0 must be a real condition, not treated as "empty/any".
        // (new Carbon(...) is typed as Carbon; Carbon::create() is Carbon|false and trips phpstan)
        $midnight = new Carbon('2026-06-25 00:00:00');
        $this->assertTrue(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, '0', null, null, $midnight),
            'hour="0" at 00:xx should run (0 is a valid hour, not empty).'
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, null, null, '0', null, null, $now),
            'hour="0" at 03:xx should not run.'
        );
    }

    /**
     * (b4) Week / month / day conditions gate correctly (derived from $now to avoid hardcoding).
     *
     * @return void
     */
    public function testCalendarConditionsGate()
    {
        $now = $this->now();

        $matchWeek    = (string) $now->dayOfWeekIso;
        $mismatchWeek = (string) (($now->dayOfWeekIso % 7) + 1);

        $this->assertTrue(
            ScheduleCommand::isOperationLogClearDue(true, 180, $matchWeek, null, null, null, null, null, $now)
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 180, $mismatchWeek, null, null, null, null, null, $now)
        );

        // month / day
        $this->assertTrue(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, '6', '25', null, null, null, $now)
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, '7', '25', null, null, null, $now)
        );
        $this->assertFalse(
            ScheduleCommand::isOperationLogClearDue(true, 180, null, '6', '24', null, null, null, $now)
        );
    }
}
