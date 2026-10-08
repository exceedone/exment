<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\LoginHistory;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\GeoIp\GeoIpService;
use Carbon\Carbon;

class ScheduleCommand extends Command
{
    use CommandTrait;
    use NotifyScheduleTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Schedule Batch';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->debugLog('Exment schedule command called.');
        $this->notify();
        $this->backup();
        $this->clearOperationLog();
        $this->clearLoginHistory();
        $this->updateGeoIp();
        $this->pluginBatch();
        return 0;
    }

    // @phpstan-ignore-next-line
    protected function backup()
    {
        if (!boolval(System::backup_enable_automatic())) {
            return;
        }

        $now = Carbon::now();
        $hh = $now->hour;
        if ($hh != System::backup_automatic_hour()) {
            return;
        }

        // set date as minute and second is 0
        $nowHour = Carbon::create($now->year, $now->month, $now->day, $now->hour, 0, 0);

        $last_executed = System::backup_automatic_executed();
        if (!is_nullorempty($last_executed)) {
            $term = System::backup_automatic_term();
            // set date as minute and second is 0
            $last_executed = Carbon::create($last_executed->year, $last_executed->month, $last_executed->day + $term, $last_executed->hour, 0, 0);
            if ($last_executed->gt($nowHour)) {
                return;
            }
        }

        // get target
        $target = System::backup_target();
        \Artisan::call('exment:backup', !is_nullorempty($target) ? ['--target' => $target, '--schedule' => 1] : []);

        System::backup_automatic_executed($now);
    }

    /**
     * Auto-delete operation logs older than the configured retention period.
     * Runs when the current time satisfies all configured schedule conditions.
     *
     * @return void
     */
    protected function clearOperationLog()
    {
        $now = Carbon::now();
        $keepDays = System::operation_log_keep_days();

        if (!self::isOperationLogClearDue(
            boolval(System::operation_log_enable_automatic()),
            $keepDays,
            System::operation_log_automatic_week(),
            System::operation_log_automatic_month(),
            System::operation_log_automatic_day(),
            System::operation_log_automatic_hour(),
            System::operation_log_automatic_minute(),
            System::operation_log_automatic_executed(),
            $now
        )) {
            return;
        }

        $exitCode = \Artisan::call('exment:log-clear', [
            '--keep-days' => (string)(int)$keepDays,
            '--force'     => true,
        ]);

        if ($exitCode === 0) {
            // Record last execution. isOperationLogClearDue() reads this back as a run-once guard
            // so the (hourly) scheduler does not re-run the purge on every tick.
            System::operation_log_automatic_executed($now);
        }
    }

    /**
     * Decide whether the operation-log auto-delete should run at $now.
     *
     * Pure function (no DB / no side effects) so the scheduling and run-once guard logic is
     * unit-testable. NOTE: the previous implementation wrote operation_log_automatic_executed
     * but never read it back, so with the default hourly scheduler the purge ran on every tick.
     * This method restores the guard by honoring $lastExecuted.
     *
     * Empty schedule conditions mean "any". "0" is NOT empty (is_nullorempty("0") === false),
     * so hour=0 / minute=0 are honored.
     *
     * @param bool $enabled
     * @param mixed $keepDays
     * @param mixed $week    ISO day-of-week 1(Mon)..7(Sun), or empty
     * @param mixed $month   1..12, or empty
     * @param mixed $day     1..31, or empty
     * @param mixed $hour    0..23, or empty
     * @param mixed $minute  0..59, or empty
     * @param Carbon|null $lastExecuted
     * @param Carbon $now
     * @return bool
     */
    public static function isOperationLogClearDue(
        bool $enabled,
        $keepDays,
        $week,
        $month,
        $day,
        $hour,
        $minute,
        ?Carbon $lastExecuted,
        Carbon $now
    ): bool {
        if (!$enabled) {
            return false;
        }
        if (is_nullorempty($keepDays) || (int)$keepDays <= 0) {
            return false;
        }

        // day-of-week (ISO: 1=Mon..7=Sun)
        if (!is_nullorempty($week) && (string)$now->dayOfWeekIso !== (string)$week) {
            return false;
        }
        // month (1..12)
        if (!is_nullorempty($month) && (string)$now->month !== (string)$month) {
            return false;
        }
        // day-of-month (1..31)
        if (!is_nullorempty($day) && (string)$now->day !== (string)$day) {
            return false;
        }
        // hour (0..23)
        if (!is_nullorempty($hour) && (string)$now->hour !== (string)$hour) {
            return false;
        }
        // minute (0..59)
        if (!is_nullorempty($minute) && (string)$now->minute !== (string)$minute) {
            return false;
        }

        // Run-once guard: never purge more than once per calendar day, even when the (hourly)
        // scheduler fires repeatedly and the time conditions are coarse/empty.
        if ($lastExecuted instanceof Carbon && $lastExecuted->isSameDay($now)) {
            return false;
        }

        return true;
    }

    /**
     * Auto-delete login histories older than the configured retention period. Runs once a day.
     *
     * @return void
     */
    protected function clearLoginHistory()
    {
        $now = Carbon::now();
        $keepDays = System::login_history_keep_days();

        if (!self::isLoginHistoryClearDue(
            boolval(System::login_history_enable_automatic()),
            $keepDays,
            System::login_history_automatic_executed(),
            $now
        )) {
            return;
        }

        if (!hasTable(SystemTableName::LOGIN_HISTORY)) {
            return;
        }

        LoginHistory::deleteOlderThan($now->copy()->subDays((int)$keepDays)->startOfDay());

        System::login_history_automatic_executed($now);
    }

    /**
     * Decide whether the login-history auto-delete should run at $now.
     * Pure function (no DB / no side effects), same as isOperationLogClearDue().
     *
     * @param bool $enabled
     * @param mixed $keepDays
     * @param Carbon|null $lastExecuted
     * @param Carbon $now
     * @return bool
     */
    public static function isLoginHistoryClearDue(bool $enabled, $keepDays, ?Carbon $lastExecuted, Carbon $now): bool
    {
        if (!$enabled) {
            return false;
        }
        if (is_nullorempty($keepDays) || (int)$keepDays <= 0) {
            return false;
        }

        // Run-once guard: never purge more than once per calendar day.
        if ($lastExecuted instanceof Carbon && $lastExecuted->isSameDay($now)) {
            return false;
        }

        return true;
    }

    /**
     * Auto-update GeoIP database, used for login history. The database is published monthly.
     *
     * @return void
     */
    protected function updateGeoIp()
    {
        // Checked first: the database file is not opened at all while the auto-update is off,
        // nor on the other (hourly) ticks of the day the update was already tried.
        $enabled = boolval(System::login_history_geoip_auto_update());
        if (!$enabled) {
            return;
        }

        $now = Carbon::now();
        $lastExecuted = System::login_history_geoip_update_executed();
        if ($lastExecuted instanceof Carbon && $lastExecuted->isSameDay($now)) {
            return;
        }

        $buildDate = array_get(GeoIpService::getDatabaseInfo(), 'build_date');

        if (!self::isGeoIpUpdateDue($enabled, $buildDate, $lastExecuted, $now)) {
            return;
        }

        // Record before downloading, so a failing download is retried next day, not on every (hourly) tick.
        System::login_history_geoip_update_executed($now);

        // No database yet: also accept last month's file. Otherwise only this month's file is worth downloading.
        $url = is_null($buildDate) ? null : array_get(GeoIpService::getDownloadUrls($now), 0);
        \Artisan::call('exment:geoip-update', is_null($url) ? [] : ['--url' => $url]);
    }

    /**
     * Decide whether the GeoIP database auto-update should run at $now.
     * Pure function (no DB / no side effects).
     *
     * @param bool $enabled
     * @param Carbon|null $buildDate build date of the current database. null if no database.
     * @param Carbon|null $lastExecuted
     * @param Carbon $now
     * @return bool
     */
    public static function isGeoIpUpdateDue(bool $enabled, ?Carbon $buildDate, ?Carbon $lastExecuted, Carbon $now): bool
    {
        if (!$enabled) {
            return false;
        }

        // Run-once guard: never try more than once per calendar day.
        if ($lastExecuted instanceof Carbon && $lastExecuted->isSameDay($now)) {
            return false;
        }

        if (is_null($buildDate)) {
            return true;
        }

        // This month's file may not be published yet on the 1st day.
        if ($now->copy()->utc()->day < 2) {
            return false;
        }

        // Update when the current database was built before this month.
        return $buildDate->copy()->utc()->format('Ym') < $now->copy()->utc()->format('Ym');
    }

    /**
     * Execute Plugin Batch
     *
     * @return void
     */
    protected function pluginBatch()
    {
        $pluginBatches = Plugin::getBatches();

        foreach ($pluginBatches as $pluginBatch) {
            \Artisan::call("exment:batch", ['--uuid' => $pluginBatch->uuid]);
        }
    }


    // @phpstan-ignore-next-line
    protected function debugLog(string $log)
    {
        if (!boolval(config('exment.debugmode_schedule', false))) {
            return;
        }

        \Log::debug($log);
    }
}
