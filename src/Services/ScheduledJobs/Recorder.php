<?php

namespace Exceedone\Exment\Services\ScheduledJobs;

use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\Features;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\PluginDistribution\AdminNotices;
use Illuminate\Console\Events\ScheduledBackgroundTaskFinished;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Support\Facades\Event as EventFacade;

/**
 * The runs of the scheduled jobs (ISS02832), kept while the feature "scheduled_jobs" is used:
 * the start, the end, the result, the exit code and the last 4 KB of the output of each run.
 *
 * - The output goes to a file under storage (the job's own place, when it has one, is kept),
 *   its last part is kept with the run, and the file is removed.
 * - Only the last runs of each job are kept (exment.scheduled_jobs.keep_runs, 20).
 * - A job that failed alert_failures times in a row (3) is told to the system administrators
 *   once (the bell and a mail), until it runs well again.
 */
class Recorder
{
    /** The longest output kept, in bytes. */
    public const OUTPUT_BYTES = 4096;

    /** The jobs already told of, until they run well (a system setting: job => true). */
    public const ALERTED = 'scheduled_job_alerted';

    /**
     * The runs started in this process (spl_object_id of the event => id of the run).
     *
     * @var array<int, int>
     */
    protected static array $runs = [];

    protected static ?bool $tableExists = null;

    /**
     * Listen to the scheduler (the console only, from ExmentServiceProvider::bootSchedule).
     */
    public static function listen(): void
    {
        EventFacade::listen(ScheduledTaskStarting::class, function (ScheduledTaskStarting $event) {
            static::safely(function () use ($event) {
                static::starting($event->task);
            });
        });
        EventFacade::listen(ScheduledTaskFinished::class, function (ScheduledTaskFinished $event) {
            static::safely(function () use ($event) {
                static::finished($event->task, floatval($event->runtime));
            });
        });
        EventFacade::listen(ScheduledTaskFailed::class, function (ScheduledTaskFailed $event) {
            static::safely(function () use ($event) {
                static::failed($event->task, $event->exception);
            });
        });
        EventFacade::listen(ScheduledTaskSkipped::class, function (ScheduledTaskSkipped $event) {
            static::safely(function () use ($event) {
                static::skipped($event->task);
            });
        });
        EventFacade::listen(ScheduledBackgroundTaskFinished::class, function (ScheduledBackgroundTaskFinished $event) {
            static::safely(function () use ($event) {
                static::backgroundFinished($event->task);
            });
        });
    }

    /**
     * Never stop the scheduler for this.
     */
    protected static function safely(\Closure $callback): void
    {
        try {
            $callback();
        } catch (\Throwable $e) {
            \Log::warning('[Exment] scheduled jobs, the record of a run: ' . $e->getMessage());
        }
    }

    /**
     * Whether the runs are kept: the feature is used (and the table is there).
     */
    public static function recording(): bool
    {
        try {
            if (!Features::enabled(ScheduledJobs::FEATURE)) {
                return false;
            }
            return static::$tableExists ??= hasTable(ScheduledJobRun::TABLE);
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function keepRuns(): int
    {
        return max(1, intval(config('exment.scheduled_jobs.keep_runs', 20)));
    }

    public static function alertFailures(): int
    {
        return max(1, intval(config('exment.scheduled_jobs.alert_failures', 3)));
    }

    // ---------------------------------------------------------------- the events of the scheduler

    public static function starting(Event $task): void
    {
        if (!static::recording()) {
            return;
        }
        $key = ScheduledJobs::key($task);
        // the output to a file of its own, then its last part is kept (not a place the job has itself)
        if (!($task instanceof CallbackEvent) && $task->output === $task->getDefaultOutput()) {
            $task->sendOutputTo(static::outputPath($key));
        }
        $run = static::begin($key, strval($task->command ?: $key), ScheduledJobRun::TRIGGERED_BY_SYSTEM);
        static::$runs[spl_object_id($task)] = $run->id;
    }

    public static function finished(Event $task, float $runtime): void
    {
        $run = static::runOf($task);
        if (!isset($run) || $run->result !== ScheduledJobRun::RESULT_RUNNING) {
            return;
        }
        if ($task->runInBackground && !($task instanceof CallbackEvent)) {
            // it goes on in the background: schedule:finish tells its end (backgroundFinished)
            return;
        }
        $output = static::takeOutput(static::outputPath($run->job_key));
        if (is_null($task->exitCode)) {
            // not run: it was still running (withoutOverlapping)
            static::end($run, null, $output, ScheduledJobRun::RESULT_SKIPPED, $runtime);
            return;
        }
        static::end($run, intval($task->exitCode), $output, null, $runtime);
    }

    public static function failed(Event $task, \Throwable $exception): void
    {
        $run = static::runOf($task);
        if (!isset($run) || $run->result !== ScheduledJobRun::RESULT_RUNNING) {
            // the end was kept already (a failed exit code is kept by finished())
            return;
        }
        $output = static::takeOutput(static::outputPath($run->job_key));
        $output = trim(($output ?? '') . "\n" . $exception->getMessage());
        static::end($run, is_null($task->exitCode) ? null : intval($task->exitCode), $output, ScheduledJobRun::RESULT_FAILED);
    }

    public static function skipped(Event $task): void
    {
        if (!static::recording()) {
            return;
        }
        $key = ScheduledJobs::key($task);
        $now = \Carbon\Carbon::now();
        ScheduledJobRun::create([
            'job_key' => $key,
            'command' => mb_substr(strval($task->command ?: $key), 0, 1000),
            'started_at' => $now,
            'finished_at' => $now,
            'duration_ms' => 0,
            'result' => ScheduledJobRun::RESULT_SKIPPED,
            'triggered_by' => ScheduledJobRun::TRIGGERED_BY_SYSTEM,
        ]);
        static::prune($key);
    }

    /**
     * A job run in the background ended (php artisan schedule:finish, another process).
     */
    public static function backgroundFinished(Event $task): void
    {
        if (!static::recording()) {
            return;
        }
        $key = ScheduledJobs::key($task);
        $run = ScheduledJobRun::query()->where('job_key', $key)->where('triggered_by', ScheduledJobRun::TRIGGERED_BY_SYSTEM)
            ->where('result', ScheduledJobRun::RESULT_RUNNING)->orderByDesc('id')->first();
        if (!isset($run)) {
            return;
        }
        static::end($run, is_null($task->exitCode) ? 0 : intval($task->exitCode), static::takeOutput(static::outputPath($key)));
    }

    protected static function runOf(Event $task): ?ScheduledJobRun
    {
        $id = static::$runs[spl_object_id($task)] ?? null;
        return isset($id) ? ScheduledJobRun::find($id) : null;
    }

    // ---------------------------------------------------------------- the runs

    /**
     * A run starts.
     *
     * @param string $triggeredBy "system" or the id of the user
     */
    public static function begin(string $key, string $command, string $triggeredBy): ScheduledJobRun
    {
        $run = ScheduledJobRun::create([
            'job_key' => $key,
            'command' => mb_substr($command, 0, 1000),
            'started_at' => \Carbon\Carbon::now(),
            'result' => ScheduledJobRun::RESULT_RUNNING,
            'triggered_by' => mb_substr($triggeredBy, 0, 32),
        ]);
        static::prune($key);
        return $run;
    }

    /**
     * A run ends.
     *
     * @param string|null $result null: by the exit code (0: success, else failed)
     * @param float|null $runtime the seconds it took (null: since it started)
     */
    public static function end(ScheduledJobRun $run, ?int $exitCode, ?string $output, ?string $result = null, ?float $runtime = null): void
    {
        $now = \Carbon\Carbon::now();
        $result = $result ?? ($exitCode === 0 ? ScheduledJobRun::RESULT_SUCCESS : ScheduledJobRun::RESULT_FAILED);
        if (is_null($runtime) && isset($run->started_at)) {
            $runtime = max(0, $now->getTimestamp() - $run->started_at->getTimestamp());
        }
        $run->fill([
            'finished_at' => $now,
            'duration_ms' => is_null($runtime) ? null : intval(round($runtime * 1000)),
            'result' => $result,
            'exit_code' => $exitCode,
            'output_tail' => static::tail($output),
        ])->save();
        static::afterResult($run->job_key, $result);
    }

    /**
     * Keep the last runs of a job only.
     */
    public static function prune(string $key): void
    {
        $keep = ScheduledJobRun::query()->where('job_key', $key)->orderByDesc('id')->limit(static::keepRuns())->pluck('id')->all();
        if (count($keep) === 0) {
            return;
        }
        ScheduledJobRun::query()->where('job_key', $key)->whereNotIn('id', $keep)->delete();
    }

    /**
     * How many times in a row the job failed lately (the skipped and running runs not counted).
     */
    public static function failureStreak(string $key): int
    {
        $streak = 0;
        $runs = ScheduledJobRun::query()->where('job_key', $key)->whereIn('result', [ScheduledJobRun::RESULT_SUCCESS, ScheduledJobRun::RESULT_FAILED])
            ->orderByDesc('id')->pluck('result');
        foreach ($runs as $result) {
            if ($result !== ScheduledJobRun::RESULT_FAILED) {
                break;
            }
            $streak++;
        }
        return $streak;
    }

    /**
     * Whether the job is shown red: its last alert_failures runs failed.
     */
    public static function failing(string $key): bool
    {
        return static::failureStreak($key) >= static::alertFailures();
    }

    /**
     * After a run: a failure that makes alert_failures in a row is told once; a good run ends it.
     */
    protected static function afterResult(string $key, string $result): void
    {
        if ($result === ScheduledJobRun::RESULT_SUCCESS) {
            $alerted = static::alerted();
            if (array_key_exists($key, $alerted)) {
                unset($alerted[$key]);
                System::scheduled_job_alerted($alerted);
            }
            return;
        }
        if ($result !== ScheduledJobRun::RESULT_FAILED) {
            return;
        }
        $streak = static::failureStreak($key);
        if ($streak < static::alertFailures()) {
            return;
        }
        $alerted = static::alerted();
        if (array_key_exists($key, $alerted)) {
            return;
        }
        $alerted[$key] = true;
        System::scheduled_job_alerted($alerted);
        static::notify($key, $streak);
    }

    /**
     * @return array<string, bool>
     */
    protected static function alerted(): array
    {
        System::clearRequestSession();
        $value = json_decode(strval(json_encode(System::scheduled_job_alerted() ?? [])), true);
        return is_array($value) ? array_map('boolval', $value) : [];
    }

    /**
     * Tell the system administrators (the bell and a mail).
     */
    protected static function notify(string $key, int $count): void
    {
        $name = ScheduledJobs::label($key);
        $subject = strval(exmtrans('scheduled_jobs.notice_subject', ['name' => $name]));
        $body = strval(exmtrans('scheduled_jobs.notice_body', ['name' => $name, 'count' => $count])) . "\n\n" . admin_url('scheduled_jobs');
        foreach (AdminNotices::administrators() as $user) {
            try {
                $navbar = new NotifyNavbar();
                $navbar->fill([
                    'notify_id' => 0,
                    'target_user_id' => $user->id,
                    'notify_subject' => mb_substr($subject, 0, 200),
                    'notify_body' => mb_substr($body, 0, 2000),
                    'trigger_user_id' => null,
                ]);
                $navbar->save();
            } catch (\Throwable $e) {
                \Log::warning('[Exment] scheduled jobs, the notice to the administrators: ' . $e->getMessage());
            }
            try {
                if (!is_null(NotifyService::getAddress($user))) {
                    NotifyService::notifyMail(['to' => $user, 'subject' => $subject, 'body' => $body]);
                }
            } catch (\Throwable $e) {
                \Log::warning('[Exment] scheduled jobs, the mail to the administrators: ' . $e->getMessage());
            }
        }
    }

    // ---------------------------------------------------------------- the output

    public static function outputDir(): string
    {
        $dir = storage_path('framework' . DIRECTORY_SEPARATOR . 'exment-scheduled-jobs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        return $dir;
    }

    /**
     * Where the scheduler writes the output of a job, until its end keeps the last of it.
     */
    public static function outputPath(string $key): string
    {
        return static::outputDir() . DIRECTORY_SEPARATOR . sha1($key) . '.log';
    }

    /**
     * The last part of an output file (null: none), and the file is removed.
     */
    public static function takeOutput(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $text = null;
        $size = @filesize($path);
        $fp = @fopen($path, 'rb');
        if ($fp !== false) {
            if (is_int($size) && $size > static::OUTPUT_BYTES) {
                fseek($fp, 0 - static::OUTPUT_BYTES, SEEK_END);
            }
            $text = stream_get_contents($fp);
            fclose($fp);
        }
        @unlink($path);
        return is_string($text) ? $text : null;
    }

    /**
     * At most OUTPUT_BYTES of the end of a text, valid UTF-8.
     */
    public static function tail(?string $text): ?string
    {
        if (is_null($text)) {
            return null;
        }
        $text = trim(mb_scrub($text, 'UTF-8'));
        if ($text === '') {
            return null;
        }
        if (strlen($text) > static::OUTPUT_BYTES) {
            $text = mb_strcut($text, strlen($text) - static::OUTPUT_BYTES, static::OUTPUT_BYTES, 'UTF-8');
        }
        return $text;
    }

    /**
     * For the tests.
     */
    public static function reset(): void
    {
        static::$runs = [];
        static::$tableExists = null;
    }
}
