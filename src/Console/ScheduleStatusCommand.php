<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Model\ScheduledJobRun;
use Exceedone\Exment\Services\ScheduledJobs\Recorder;
use Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs;
use Exceedone\Exment\Services\SystemRequire\Tuning\ScheduleStatus;
use Exceedone\Exment\Services\SystemRequire\Tuning\ScheduleTuning;
use Illuminate\Console\Command;

/**
 * The scheduled jobs of the site and their last runs (ISS02832), as the page "Scheduled jobs":
 *
 *   php artisan exment:schedule-status          a table
 *   php artisan exment:schedule-status --json   per job: when, the next run, the last run, the failures in a row
 *
 * On a SaaS each tenant has its own database: the jobs and runs of that tenant.
 */
class ScheduleStatusCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:schedule-status {--json : as JSON}';

    /**
     * @var string
     */
    protected $description = 'Show the scheduled jobs and their last runs';

    /**
     * @return int
     */
    public function handle()
    {
        $this->initExmentCommand();
        $status = static::status();
        if (boolval($this->option('json'))) {
            $this->line(strval(json_encode($status, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)));
            return 0;
        }
        $this->line('schedule:run: ' . ($status['last_schedule_run'] ?? '-') . ($status['cron_alive'] ? '' : ' (not running?)'));
        $this->table(['job', 'when', 'next', 'enabled', 'last run', 'result', 'failures'], array_map(function ($job) {
            return [
                $job['key'],
                $job['expression'],
                $job['next_run'] ?? '-',
                $job['enabled'] ? 'yes' : 'no',
                $job['last_run']['started_at'] ?? '-',
                $job['last_run']['result'] ?? '-',
                $job['failures'],
            ];
        }, $status['jobs']));
        return 0;
    }

    /**
     * @return array{cron_alive: bool, last_schedule_run: ?string, recording: bool, jobs: array<int, array<string, mixed>>}
     */
    public static function status(): array
    {
        $tableExists = hasTable(ScheduledJobRun::TABLE);
        $jobs = [];
        foreach (ScheduledJobs::jobs() as $job) {
            $last = $tableExists ? ScheduledJobRun::query()->where('job_key', $job['key'])->orderByDesc('id')->first() : null;
            $jobs[] = [
                'key' => $job['key'],
                'label' => $job['label'],
                'expression' => $job['expression'],
                'when' => $job['when'],
                'next_run' => isset($job['next']) ? $job['next']->format('Y-m-d H:i:s') : null,
                'enabled' => $job['enabled'],
                'disabled_reason' => $job['reason'],
                'last_run' => isset($last) ? [
                    'started_at' => $last->started_at?->format('Y-m-d H:i:s'),
                    'finished_at' => $last->finished_at?->format('Y-m-d H:i:s'),
                    'result' => $last->result,
                    'exit_code' => $last->exit_code,
                    'duration_ms' => $last->duration_ms,
                    'triggered_by' => $last->triggered_by,
                ] : null,
                // the failures in a row, and the failed runs among those kept
                'failures' => $tableExists ? Recorder::failureStreak($job['key']) : 0,
                'failed_runs' => $tableExists ? ScheduledJobRun::query()->where('job_key', $job['key'])->where('result', ScheduledJobRun::RESULT_FAILED)->count() : 0,
                'failing' => $tableExists && Recorder::failing($job['key']),
            ];
        }
        $lastFinished = ScheduleStatus::read()['last_finished'];
        return [
            'cron_alive' => (new ScheduleTuning())->alive(),
            'last_schedule_run' => is_null($lastFinished) ? null : \Carbon\Carbon::createFromTimestamp($lastFinished, strval(config('app.timezone')))->format('Y-m-d H:i:s'),
            'recording' => Recorder::recording(),
            'jobs' => $jobs,
        ];
    }
}
