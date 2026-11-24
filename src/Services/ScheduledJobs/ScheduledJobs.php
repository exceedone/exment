<?php

namespace Exceedone\Exment\Services\ScheduledJobs;

use Exceedone\Exment\ExmentServiceProvider;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Services\DemoMode;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\SystemRequire\Tuning\CliPhp;
use Exceedone\Exment\Services\SystemRequire\Tuning\ScheduleStatus;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Symfony\Component\Process\Process;

/**
 * The page "Scheduled jobs" (ISS02832, the feature "scheduled_jobs"): what
 * php artisan schedule:run does (the same list as the console, ExmentServiceProvider::registerSchedule),
 * when, whether it is used, and "Run now" for the system administrators.
 *
 * "Run now" starts "php artisan <command>" in the background with the mutex of
 * withoutOverlapping (a job that runs is not started again), and the run is kept with
 * the user who started it. Its end is told by "php artisan exment:scheduled-job-finish".
 * A server where PHP cannot start a command (no proc_open, as a shared host) has no button.
 */
class ScheduledJobs
{
    public const FEATURE = 'scheduled_jobs';

    /** The names (scheduled_jobs.jobs.*) of the commands of Exment. */
    public const JOBS = [
        'exment:schedule' => 'schedule',
        'exment:safetywatch' => 'safetywatch',
        'exment:file-server-purge' => 'file_server_purge',
        'exment:meili-reconcile' => 'meili_reconcile',
        'exment:maintenance tick' => 'maintenance_tick',
    ];

    /** The feature a job is for: when it is not used, the job does nothing. */
    public const JOB_FEATURES = [
        'exment:safetywatch' => Features::SAFETY_CHECK,
    ];

    /** A run of "Run now" still running after this many minutes is not waited for. */
    public const RUNNING_MINUTES = 60;

    /** @var bool|null whether PHP may start a command (a test sets it) */
    public static $processAvailable;

    /** @var \Closure|null what starts the command line (a test sets it) */
    public static $launcher;

    /**
     * A new schedule with the jobs of Exment, as the console makes it.
     */
    public static function schedule(): Schedule
    {
        $schedule = new Schedule(config('app.schedule_timezone', config('app.timezone')));
        ExmentServiceProvider::registerSchedule($schedule);
        return $schedule;
    }

    /**
     * The short name of a job ("exment:schedule", "exment:batch 3").
     */
    public static function key(Event $event): string
    {
        return ScheduleStatus::taskName($event);
    }

    /**
     * The event of a job (null: there is none).
     */
    public static function event(string $key): ?Event
    {
        foreach (static::schedule()->events() as $event) {
            if (static::key($event) === $key) {
                return $event;
            }
        }
        return null;
    }

    /**
     * The jobs, for the page and "php artisan exment:schedule-status".
     *
     * @return array<int, array{key: string, label: string, command: string, expression: string, when: string, next: ?\Carbon\Carbon, enabled: bool, reason: ?string, overlapping: bool, event: Event}>
     */
    public static function jobs(): array
    {
        $jobs = [];
        foreach (static::schedule()->events() as $event) {
            $key = static::key($event);
            $reason = static::disabledReason($event, $key);
            $expression = strval($event->getExpression());
            try {
                $next = \Carbon\Carbon::instance($event->nextRunDate())->setTimezone(strval(config('app.timezone')));
            } catch (\Throwable $e) {
                $next = null;
            }
            $jobs[] = [
                'key' => $key,
                'label' => static::label($key),
                'command' => $key,
                'expression' => $expression,
                'when' => CronWords::describe($expression),
                'next' => $next,
                'enabled' => is_null($reason),
                'reason' => $reason,
                'overlapping' => boolval($event->withoutOverlapping),
                'event' => $event,
            ];
        }
        return $jobs;
    }

    /**
     * The name of a job: the command of Exment in words, the name of the plugin of a batch.
     */
    public static function label(string $key): string
    {
        // Exment's, and the ones an application added (CoreExtensions::addSchedule())
        $names = \Exceedone\Exment\Services\CoreExtensions::scheduledJobNames();
        if (array_key_exists($key, $names)) {
            return strval(exmtrans('scheduled_jobs.jobs.' . $names[$key]));
        }
        if (preg_match('/^exment:batch (\d+)$/D', $key, $m)) {
            try {
                $plugin = Plugin::find(intval($m[1]));
            } catch (\Throwable $e) {
                $plugin = null;
            }
            $name = isset($plugin) ? strval($plugin->plugin_view_name ?: $plugin->plugin_name) : $m[1];
            return strval(exmtrans('scheduled_jobs.jobs.batch', ['name' => $name]));
        }
        return $key;
    }

    /**
     * Why a job does not run (null: it runs): "feature" (the feature it is for is not used)
     * or "when" (its condition says no, as the safety check not watching the feed).
     */
    public static function disabledReason(Event $event, string $key): ?string
    {
        $feature = static::JOB_FEATURES[$key] ?? null;
        if (isset($feature) && !Features::enabled($feature)) {
            return 'feature';
        }
        try {
            // the conditions of the job itself (the console adds the skip in a maintenance; not here)
            if ($event->filtersPass(app())) {
                return null;
            }
            // withoutOverlapping says no while it runs: it is used all the same
            return $event->withoutOverlapping && $event->mutex->exists($event) ? null : 'when';
        } catch (\Throwable $e) {
            return 'when';
        }
    }

    /**
     * The text of disabledReason().
     */
    public static function disabledText(string $key, ?string $reason): string
    {
        if ($reason === 'feature') {
            return strval(exmtrans('scheduled_jobs.disabled_feature', ['feature' => Features::label(static::JOB_FEATURES[$key] ?? '')]));
        }
        return strval(exmtrans('scheduled_jobs.disabled_when'));
    }

    // ---------------------------------------------------------------- run now

    /**
     * Whether PHP may start a command here (proc_open, as the scheduler itself).
     */
    public static function processAvailable(): bool
    {
        if (!is_null(static::$processAvailable)) {
            return static::$processAvailable;
        }
        $disabled = array_map('trim', explode(',', strtolower(strval(ini_get('disable_functions')))));
        foreach (['proc_open', 'proc_get_status', 'proc_close'] as $function) {
            if (!function_exists($function) || in_array($function, $disabled, true)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Why "Run now" cannot start the job (null: it can): no_process, disabled, running, command.
     *
     * @param array<string, mixed> $job a job of jobs()
     */
    public static function runUnavailable(array $job): ?string
    {
        if (DemoMode::enabled()) {
            return 'demo';
        }
        if (!static::processAvailable()) {
            return 'no_process';
        }
        $event = $job['event'] ?? null;
        $key = strval($job['key'] ?? '');
        if (!($event instanceof Event) || $event instanceof CallbackEvent || preg_match('/^[A-Za-z0-9:_\- ]+$/D', $key) !== 1) {
            return 'command';
        }
        if (!boolval($job['enabled'] ?? false)) {
            return 'disabled';
        }
        if (static::isRunning($event, $key)) {
            return 'running';
        }
        return null;
    }

    /**
     * The text of a reason of runUnavailable() (or of runNow()).
     */
    public static function unavailableText(string $reason): string
    {
        if ($reason === 'demo') {
            return DemoMode::message();
        }
        return strval(exmtrans('scheduled_jobs.run_unavailable.' . $reason));
    }

    /**
     * Whether the job runs now: its mutex is taken (withoutOverlapping, or a "Run now"), or
     * a run of it has not finished yet (not older than RUNNING_MINUTES).
     */
    public static function isRunning(Event $event, string $key): bool
    {
        try {
            if ($event->mutex->exists(static::lockEvent($event))) {
                return true;
            }
        } catch (\Throwable $e) {
            // the cache cannot be read: the runs say
        }
        $last = ScheduledJobRun::query()->where('job_key', $key)->orderByDesc('id')->first();
        return isset($last, $last->started_at) && $last->result === ScheduledJobRun::RESULT_RUNNING
            && $last->started_at->gt(\Carbon\Carbon::now()->subMinutes(static::RUNNING_MINUTES));
    }

    /**
     * The event as it is locked: the same mutex as withoutOverlapping; a job without it is
     * locked for RUNNING_MINUTES at most (the default of Laravel is a day).
     */
    protected static function lockEvent(Event $event): Event
    {
        $lock = clone $event;
        if (!$event->withoutOverlapping) {
            $lock->expiresAt = static::RUNNING_MINUTES;
        }
        return $lock;
    }

    /**
     * Start a job now, in the background.
     *
     * @param string|int $userId the user who started it
     * @return array{0: bool, 1: ?string} done, and why not (runUnavailable, not_found, failed)
     */
    public static function runNow(string $key, $userId): array
    {
        $job = collect(static::jobs())->first(function ($job) use ($key) {
            return $job['key'] === $key;
        });
        if (!isset($job)) {
            return [false, 'not_found'];
        }
        $reason = static::runUnavailable($job);
        if (isset($reason)) {
            return [false, $reason];
        }
        $event = $job['event'];
        $lock = static::lockEvent($event);
        if (!$event->mutex->create($lock)) {
            return [false, 'running'];
        }

        $run = Recorder::begin($key, $key, strval($userId));
        try {
            $line = static::backgroundCommand($key, $run);
            $launcher = static::$launcher ?? function (string $line) {
                Process::fromShellCommandline($line, base_path(), null, null, null)->run();
            };
            $launcher($line);
        } catch (\Throwable $e) {
            \Log::warning('[Exment] scheduled jobs, run now: ' . $e->getMessage());
            $event->mutex->forget($lock);
            Recorder::end($run, 1, $e->getMessage());
            return [false, 'failed'];
        }
        return [true, null];
    }

    /**
     * The end of a "Run now" (php artisan exment:scheduled-job-finish): the run is kept and the mutex freed.
     */
    public static function finishRun(int $runId, int $exitCode): bool
    {
        $run = ScheduledJobRun::find($runId);
        if (!isset($run)) {
            return false;
        }
        $path = static::runOutputPath($run);
        Recorder::end($run, $exitCode, Recorder::takeOutput($path));
        try {
            $event = static::event($run->job_key);
            if (isset($event)) {
                $event->mutex->forget(static::lockEvent($event));
            }
        } catch (\Throwable $e) {
            \Log::warning('[Exment] scheduled jobs, the end of a run: ' . $e->getMessage());
        }
        return true;
    }

    /**
     * The command line that runs the job in the background and tells its end.
     */
    public static function backgroundCommand(string $key, ScheduledJobRun $run): string
    {
        $php = CliPhp::path();
        $artisan = escapeshellarg(base_path('artisan'));
        $output = escapeshellarg(static::runOutputPath($run));
        $command = "{$php} {$artisan} {$key}";
        $finish = "{$php} {$artisan} exment:scheduled-job-finish {$run->id}";
        if (windows_os()) {
            return 'start /b cmd /v:on /c "(' . $command . ' > ' . $output . ' 2>&1 & ' . $finish . ' !ERRORLEVEL!) > NUL 2>&1"';
        }
        return '(' . $command . ' > ' . $output . ' 2>&1 ; ' . $finish . ' "$?") > /dev/null 2>&1 &';
    }

    /**
     * Where a "Run now" writes, until its end keeps the last of it.
     */
    public static function runOutputPath(ScheduledJobRun $run): string
    {
        return Recorder::outputDir() . DIRECTORY_SEPARATOR . 'run-' . $run->id . '.log';
    }
}
