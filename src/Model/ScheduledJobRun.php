<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Model;

/**
 * A run of a scheduled job (php artisan schedule:run, or "Run now" on the page "Scheduled
 * jobs", ISS02832). Only the last runs of each job are kept (Services\ScheduledJobs\Recorder).
 *
 * @property int $id
 * @property string $job_key the short name of the command ("exment:schedule", "exment:batch 3")
 * @property string|null $command the command line
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property string $result running, success, failed or skipped
 * @property int|null $exit_code
 * @property string|null $output_tail the last 4 KB of what it wrote
 * @property string $triggered_by "system" (the scheduler) or the id of the user who ran it
 */
class ScheduledJobRun extends Model
{
    public const TABLE = 'scheduled_job_runs';

    public const RESULT_RUNNING = 'running';
    public const RESULT_SUCCESS = 'success';
    public const RESULT_FAILED = 'failed';
    public const RESULT_SKIPPED = 'skipped';

    /** The scheduler ran it (else the id of the user). */
    public const TRIGGERED_BY_SYSTEM = 'system';

    protected $table = self::TABLE;

    public $timestamps = false;

    protected $fillable = [
        'job_key', 'command', 'started_at', 'finished_at', 'duration_ms', 'result', 'exit_code', 'output_tail', 'triggered_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'duration_ms' => 'integer',
        'exit_code' => 'integer',
    ];

    public function isManual(): bool
    {
        return $this->triggered_by !== static::TRIGGERED_BY_SYSTEM;
    }
}
