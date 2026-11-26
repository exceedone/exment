<?php

namespace Exceedone\Exment\Model;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * A run of the backup or the restore (ISS02943), from the screen, the command or the schedule.
 * Services\BackupRestore\BackupRuns starts them (one at a time) and keeps the last ones;
 * Services\BackupRestore\RunProgress writes their stage, progress, heartbeat and log.
 *
 * @property int $id
 * @property string $uuid
 * @property string $kind backup, restore
 * @property string $triggered_by screen, command, schedule
 * @property string $status queued, running, succeeded, warning, failed, stalled
 * @property string|null $stage
 * @property int $progress 0 to 100
 * @property int $stage_done
 * @property int $stage_total
 * @property array<int, array<string, mixed>>|null $stages
 * @property array<int, string>|null $targets
 * @property string|null $file_name
 * @property int|null $file_size
 * @property int $warnings
 * @property int $errors
 * @property string|null $message
 * @property string|null $log_path
 * @property Carbon|null $heartbeat_at
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $created_user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BackupRun extends Model
{
    public const TABLE = 'backup_runs';

    public const KIND_BACKUP = 'backup';
    public const KIND_RESTORE = 'restore';

    public const TRIGGER_SCREEN = 'screen';
    public const TRIGGER_COMMAND = 'command';
    public const TRIGGER_SCHEDULE = 'schedule';

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    /** finished, with steps that went wrong (a folder not copied, a schema not migrated) */
    public const STATUS_WARNING = 'warning';
    public const STATUS_FAILED = 'failed';
    /** no heartbeat for a long time: the process stopped (BackupRuns::markStalled) */
    public const STATUS_STALLED = 'stalled';

    /** A run not over yet: it holds the lock. */
    public const ACTIVE = [self::STATUS_QUEUED, self::STATUS_RUNNING];

    public const STATUSES = [
        self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_SUCCEEDED,
        self::STATUS_WARNING, self::STATUS_FAILED, self::STATUS_STALLED,
    ];

    /**
     * The stages of each kind, with their weight in the whole progress.
     */
    public const STAGES = [
        self::KIND_BACKUP => [
            'prepare' => 2,
            'dump' => 40,
            'files' => 30,
            'archive' => 20,
            'upload' => 8,
        ],
        self::KIND_RESTORE => [
            'verify' => 3,
            'extract' => 15,
            'load' => 45,
            'files' => 22,
            'finish' => 15,
        ],
    ];

    protected $table = self::TABLE;

    protected $guarded = ['id'];

    /**
     * As the defaults of the table (a new run counts from 0 before it is read again).
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'progress' => 0,
        'stage_done' => 0,
        'stage_total' => 0,
        'warnings' => 0,
        'errors' => 0,
    ];

    protected $casts = [
        'progress' => 'integer',
        'stage_done' => 'integer',
        'stage_total' => 'integer',
        'stages' => 'array',
        'targets' => 'array',
        'file_size' => 'integer',
        'warnings' => 'integer',
        'errors' => 'integer',
        'heartbeat_at' => 'datetime',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_user_id' => 'integer',
    ];

    public function isActive(): bool
    {
        return in_array($this->status, static::ACTIVE, true);
    }

    /**
     * The stages of the kind of this run, with their weights.
     *
     * @return array<string, int>
     */
    public function stageWeights(): array
    {
        return static::STAGES[$this->kind] ?? [];
    }

    /**
     * The record of a stage (null: not started).
     *
     * @return array<string, mixed>|null
     */
    public function stageRow(string $name): ?array
    {
        foreach ($this->stages ?? [] as $row) {
            if (($row['name'] ?? null) === $name) {
                return $row;
            }
        }
        return null;
    }

    /**
     * The seconds since the start (to the end, when it ended).
     */
    public function elapsedSeconds(?Carbon $now = null): ?int
    {
        if (!isset($this->started_at)) {
            return null;
        }
        $end = $this->finished_at ?? $now ?? Carbon::now();

        return max(0, (int) $this->started_at->diffInSeconds($end, true));
    }

    /**
     * A rough time left, in seconds: the time so far, spread on what is done (null: too early to tell).
     */
    public function remainingSeconds(?Carbon $now = null): ?int
    {
        if (!$this->isActive() || $this->status === static::STATUS_QUEUED) {
            return null;
        }
        $elapsed = $this->elapsedSeconds($now);
        $progress = max(0, min(100, $this->progress));
        if ($elapsed === null || $progress < 3 || $elapsed < 2) {
            return null;
        }
        if ($progress >= 100) {
            return 0;
        }

        return (int) round($elapsed * (100 - $progress) / $progress);
    }

    /**
     * What the screen polls (no path, no secret: the log is masked when written).
     *
     * @return array<string, mixed>
     */
    public function toStatusArray(?Carbon $now = null): array
    {
        $stages = [];
        foreach (array_keys($this->stageWeights()) as $name) {
            $row = $this->stageRow($name);
            $stages[] = [
                'name' => $name,
                'label' => strval(exmtrans("backup.run.stages.{$name}")),
                'state' => isset($row['finished_at']) ? 'done' : ($name === $this->stage && $this->isActive() ? 'current' : (isset($row) ? 'stopped' : 'waiting')),
                'started_at' => $row['started_at'] ?? null,
                'finished_at' => $row['finished_at'] ?? null,
                'seconds' => $row['seconds'] ?? null,
                'count' => $row['count'] ?? null,
                'bytes' => $row['bytes'] ?? null,
                'warnings' => $row['warnings'] ?? 0,
                'errors' => $row['errors'] ?? 0,
            ];
        }

        return [
            'uuid' => $this->uuid,
            'kind' => $this->kind,
            'kind_label' => strval(exmtrans("backup.run.kinds.{$this->kind}")),
            'status' => $this->status,
            'status_label' => strval(exmtrans("backup.run.statuses.{$this->status}")),
            'active' => $this->isActive(),
            'stage' => $this->stage,
            'stage_label' => isset($this->stage) ? strval(exmtrans("backup.run.stages.{$this->stage}")) : null,
            'progress' => $this->progress,
            'stage_done' => $this->stage_done,
            'stage_total' => $this->stage_total,
            'stages' => $stages,
            'started_at' => $this->started_at?->format('Y-m-d H:i:s'),
            'finished_at' => $this->finished_at?->format('Y-m-d H:i:s'),
            'elapsed_seconds' => $this->elapsedSeconds($now),
            'remaining_seconds' => $this->remainingSeconds($now),
            'file_name' => $this->file_name,
            'file_size' => $this->file_size,
            'warnings' => $this->warnings,
            'errors' => $this->errors,
            'message' => $this->message,
        ];
    }
}
