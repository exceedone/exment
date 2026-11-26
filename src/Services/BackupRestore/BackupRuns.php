<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Carbon\Carbon;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRunLockedException;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Jobs\BackupRunJob;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Services\LogManagement\LogWriter;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\PluginDistribution\AdminNotices;
use Exceedone\Exment\Services\QueueWorker;
use ExmentAdminCore\Admin\Auth\Database\OperationLog as OperationLogModel;
use Illuminate\Support\Str;

/**
 * The runs of the backup and the restore (ISS02943).
 *
 * - One at a time: a run starts only when no other is queued or running (start()). A run whose
 *   heartbeat is older than exment.backup.stale_minutes, while no process of this server holds
 *   the lock file, has stopped: it is marked "stalled" and no longer holds the lock.
 * - Where it runs: a backup from the screen goes to the queue when a worker is running (its
 *   heartbeat, QueueWorker); else it runs in the request, as before. A restore always runs where
 *   it is asked (the request or the command): it replaces the database the queue and the sessions
 *   are in, and puts the site in maintenance.
 * - At the end: the result told to the system administrators (the bell and a mail), a line in
 *   the operation log, and only the last exment.backup.keep_runs runs kept (with their logs).
 */
class BackupRuns
{
    public const LOG_DIR = 'app/backup-runs';

    public const LOCK_FILE = '.lock';

    // ---------------------------------------------------------------- start

    /**
     * Start a run (queued or running), or refuse when another is not over.
     *
     * @param array<string, mixed> $attributes targets, file_name
     * @throws BackupRunLockedException
     */
    public static function start(string $kind, string $trigger, array $attributes = [], bool $queued = false): BackupRun
    {
        static::markStalled();

        if (static::processLocked()) {
            throw new BackupRunLockedException(strval(exmtrans('backup.run.message.locked')));
        }

        return \DB::transaction(function () use ($kind, $trigger, $attributes, $queued) {
            /** @var BackupRun|null $active */
            $active = BackupRun::query()->whereIn('status', BackupRun::ACTIVE)->lockForUpdate()->first();
            if (isset($active)) {
                throw new BackupRunLockedException(strval(exmtrans('backup.run.message.locked')), $active);
            }
            $now = Carbon::now();
            $run = new BackupRun();
            $run->uuid = (string) Str::uuid();
            $run->kind = $kind;
            $run->triggered_by = $trigger;
            $run->status = $queued ? BackupRun::STATUS_QUEUED : BackupRun::STATUS_RUNNING;
            $run->progress = 0;
            $run->targets = isset($attributes['targets']) ? array_values(array_map('strval', (array) $attributes['targets'])) : null;
            $run->file_name = isset($attributes['file_name']) ? mb_substr(strval($attributes['file_name']), 0, 100) : null;
            $run->heartbeat_at = $now;
            $run->started_at = $queued ? null : $now;
            $run->created_user_id = static::userId();
            $run->save();

            return $run;
        });
    }

    /**
     * Whether a backup of the screen goes to the queue: a queue is used and its worker runs.
     */
    public static function queueAvailable(): bool
    {
        if (!boolval(config('exment.backup.use_queue', true))) {
            return false;
        }
        return QueueWorker::used() && QueueWorker::state() === 'ok';
    }

    /**
     * Give a queued run to the worker.
     */
    public static function dispatch(BackupRun $run): void
    {
        BackupRunJob::dispatch($run->uuid);
    }

    // ---------------------------------------------------------------- the runs

    /**
     * Run a backup of a started run: the result of Backup::execute (0: done).
     *
     * @param array<int, string>|string|null $target
     */
    public static function runBackup(BackupRun $run, $target = null, bool $schedule = false, ?callable $output = null): int
    {
        $progress = new RunProgress($run, $output);
        $progress->begin();
        static::operationLog($run, 'start');

        $backup = new Backup();
        $backup->setProgress($progress);
        try {
            $result = $backup->execute($target ?? $run->targets, $schedule);
            static::finished($progress, $result === 0 && intval($run->errors) === 0 ? BackupRun::STATUS_SUCCEEDED : BackupRun::STATUS_WARNING, $result === 0 ? null : strval(exmtrans('backup.run.hints.see_log')));
            return $result;
        } catch (\Throwable $e) {
            static::failed($progress, $e);
            throw $e;
        } finally {
            $progress->releaseLock();
        }
    }

    /**
     * Run a restore of a started run: the result of Restore::execute (0: done).
     */
    public static function runRestore(BackupRun $run, Restore $restore, ?string $file, ?bool $tmp = null, ?callable $output = null): int
    {
        $progress = new RunProgress($run, $output);
        $progress->begin();

        $restore->setProgress($progress);
        try {
            $result = $restore->execute($file, $tmp);
            if ($restore->isConfigSkipped()) {
                $progress->warn(strval(exmtrans('backup.message.restore_config_skipped')));
            }
            // the start is written now: written before, the restore would have replaced it
            static::operationLog($run, 'start');
            static::finished($progress, $result === 0 && intval($run->errors) === 0 ? BackupRun::STATUS_SUCCEEDED : BackupRun::STATUS_WARNING, $result === 0 ? null : strval(exmtrans('backup.run.hints.see_log')));
            return $result;
        } catch (\Throwable $e) {
            static::operationLog($run, 'start');
            static::failed($progress, $e);
            throw $e;
        } finally {
            $progress->releaseLock();
        }
    }

    protected static function finished(RunProgress $progress, string $status, ?string $message): void
    {
        $progress->finish($status, $message);
        $run = $progress->run();
        if (!isset($run)) {
            return;
        }
        static::afterFinish($run);
    }

    protected static function failed(RunProgress $progress, \Throwable $e): void
    {
        $progress->error(get_class($e) . ': ' . $e->getMessage());
        \Log::error('[Exment] ' . ($progress->run()->kind ?? 'backup') . ' failed: ' . SecretMasker::mask($e->getMessage()));
        $progress->finish(BackupRun::STATUS_FAILED, static::reason($e) . ' ' . strval(exmtrans('backup.run.hints.' . static::hint($e))));
        $run = $progress->run();
        if (isset($run)) {
            static::afterFinish($run);
        }
    }

    protected static function afterFinish(BackupRun $run): void
    {
        foreach ([fn () => static::operationLog($run, 'finish'), fn () => static::notify($run), fn () => static::keep()] as $step) {
            try {
                $step();
            } catch (\Throwable $e) {
                \Log::warning('[Exment] backup run, after the end: ' . SecretMasker::mask($e->getMessage()));
            }
        }
    }

    /**
     * Why it failed, without a secret (shown on the screen and in the mail).
     */
    public static function reason(\Throwable $e): string
    {
        if ($e instanceof BackupRestoreCheckException || $e instanceof InvalidZipEntryException || $e instanceof BackupRunLockedException) {
            return SecretMasker::mask($e->getMessage());
        }
        return SecretMasker::mask(mb_substr(class_basename($e) . ': ' . $e->getMessage(), 0, 500));
    }

    /**
     * What to do next (the key of backup.run.hints).
     */
    public static function hint(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if (str_contains($message, 'no space') || str_contains($message, 'disk full') || str_contains($message, 'disk quota')) {
            return 'disk_full';
        }
        if ($e instanceof BackupRestoreCheckException) {
            return 'command';
        }
        if ($e instanceof InvalidZipEntryException) {
            return 'bad_file';
        }
        if (str_contains($message, 'permission denied')) {
            return 'permission';
        }
        return 'see_log';
    }

    // ---------------------------------------------------------------- the lock

    /**
     * The runs whose heartbeat stopped are "stalled" (they no longer hold the lock).
     *
     * @return int the runs marked
     */
    public static function markStalled(?Carbon $now = null): int
    {
        $now = $now ?? Carbon::now();
        // a process of this server still runs it (a long step without heartbeat)
        if (static::processLocked()) {
            return 0;
        }
        $limit = $now->copy()->subMinutes(static::staleMinutes());
        $count = 0;
        $runs = BackupRun::query()->whereIn('status', BackupRun::ACTIVE)->get();
        foreach ($runs as $run) {
            /** @var BackupRun $run */
            $last = $run->heartbeat_at ?? $run->started_at ?? $run->created_at;
            if (isset($last) && $last->greaterThan($limit)) {
                continue;
            }
            $run->status = BackupRun::STATUS_STALLED;
            $run->finished_at = $now;
            $run->message = strval(exmtrans($run->started_at === null ? 'backup.run.message.stalled_queued' : 'backup.run.message.stalled', ['minutes' => static::staleMinutes()]));
            $run->save();
            static::appendLog($run, 'ERROR', 'stalled: no heartbeat since ' . (isset($last) ? $last->format('Y-m-d H:i:s') : '-'));
            $count++;
            static::afterFinish($run);
        }
        return $count;
    }

    public static function staleMinutes(): int
    {
        return max(1, intval(config('exment.backup.stale_minutes', 30)));
    }

    /**
     * The run not over (queued or running), if any.
     */
    public static function active(): ?BackupRun
    {
        /** @var BackupRun|null $run */
        $run = BackupRun::query()->whereIn('status', BackupRun::ACTIVE)->orderByDesc('id')->first();
        return $run;
    }

    /**
     * Hold the lock file of this server while a run goes on (released at its end, or when the
     * process dies).
     *
     * @return resource|null
     */
    public static function acquireProcessLock()
    {
        try {
            static::ensureLogDirectory();
            $handle = fopen(static::lockPath(), 'c');
            if ($handle === false) {
                return null;
            }
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                fclose($handle);
                return null;
            }
            return $handle;
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * @param resource|null $handle
     */
    public static function releaseProcessLock($handle): void
    {
        if (!is_resource($handle)) {
            return;
        }
        try {
            flock($handle, LOCK_UN);
            fclose($handle);
        } catch (\Throwable $e) {
            // closed already
        }
    }

    /**
     * Whether a process of this server holds the lock file (a run goes on here).
     */
    public static function processLocked(): bool
    {
        $path = static::lockPath();
        if (!file_exists($path)) {
            return false;
        }
        try {
            $handle = fopen($path, 'c');
            if ($handle === false) {
                return false;
            }
            $free = flock($handle, LOCK_EX | LOCK_NB);
            if ($free) {
                flock($handle, LOCK_UN);
            }
            fclose($handle);
            return !$free;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function lockPath(): string
    {
        return path_join(storage_path(static::LOG_DIR), static::LOCK_FILE);
    }

    // ---------------------------------------------------------------- the logs

    public static function ensureLogDirectory(): void
    {
        $dir = storage_path(static::LOG_DIR);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
    }

    public static function logRelativePath(BackupRun $run): string
    {
        return path_join(static::LOG_DIR, $run->uuid . '.log');
    }

    public static function logFullPath(BackupRun $run): string
    {
        return storage_path(static::logRelativePath($run));
    }

    /**
     * The log of a run (masked when written; masked again here, the settings may have changed).
     */
    public static function readLog(BackupRun $run): ?string
    {
        $path = static::logFullPath($run);
        if (!is_file($path)) {
            return null;
        }
        return SecretMasker::mask(strval(file_get_contents($path)));
    }

    protected static function appendLog(BackupRun $run, string $level, string $message): void
    {
        try {
            static::ensureLogDirectory();
            file_put_contents(static::logFullPath($run), sprintf("%s [%s] [%s] %s\n", Carbon::now()->format('Y-m-d H:i:s'), $level, $run->stage ?? '-', SecretMasker::mask($message)), FILE_APPEND | LOCK_EX);
        } catch (\Throwable $e) {
            // the log never stops anything
        }
    }

    // ---------------------------------------------------------------- after the end

    /**
     * Keep the last exment.backup.keep_runs runs (and their logs).
     */
    public static function keep(): int
    {
        $keep = max(1, intval(config('exment.backup.keep_runs', 100)));
        $ids = BackupRun::query()->whereNotIn('status', BackupRun::ACTIVE)->orderByDesc('id')->skip($keep)->take(1000)->pluck('id')->all();
        if (count($ids) === 0) {
            return 0;
        }
        foreach (BackupRun::query()->whereIn('id', $ids)->get() as $run) {
            /** @var BackupRun $run */
            $path = static::logFullPath($run);
            if (is_file($path)) {
                @unlink($path);
            }
        }
        return intval(BackupRun::query()->whereIn('id', $ids)->delete());
    }

    /**
     * Tell the system administrators how the run ended (the bell and a mail).
     */
    public static function notify(BackupRun $run): void
    {
        if (!boolval(config('exment.backup.notify', true)) || $run->isActive()) {
            return;
        }
        $kind = strval(exmtrans("backup.run.kinds.{$run->kind}"));
        $status = strval(exmtrans("backup.run.statuses.{$run->status}"));
        $subject = strval(exmtrans('backup.run.notify.subject', ['kind' => $kind, 'status' => $status]));
        $body = strval(exmtrans('backup.run.notify.body', [
            'kind' => $kind,
            'status' => $status,
            'started' => $run->started_at?->format('Y-m-d H:i:s') ?? '-',
            'finished' => $run->finished_at?->format('Y-m-d H:i:s') ?? '-',
            'file' => $run->file_name ?? '-',
            'warnings' => $run->warnings,
            'errors' => $run->errors,
        ]));
        if (!is_nullorempty($run->message)) {
            $body .= "\n\n" . strval($run->message);
        }
        if ($run->status === BackupRun::STATUS_FAILED || $run->status === BackupRun::STATUS_STALLED) {
            $body .= "\n\n" . strval(exmtrans('backup.run.notify.next'));
        }
        $body .= "\n\n" . admin_url('backup');
        $body = SecretMasker::mask($body);

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
                \Log::warning('[Exment] backup run, the bell to the administrators: ' . SecretMasker::mask($e->getMessage()));
            }
            try {
                if (!is_null(NotifyService::getAddress($user))) {
                    NotifyService::notifyMail(['to' => $user, 'subject' => $subject, 'body' => $body]);
                }
            } catch (\Throwable $e) {
                \Log::warning('[Exment] backup run, the mail to the administrators: ' . SecretMasker::mask($e->getMessage()));
            }
        }
    }

    /**
     * A line of the operation log: the start, the end or a download of the log of a run.
     */
    public static function operationLog(BackupRun $run, string $event): void
    {
        try {
            $request = app()->runningInConsole() ? null : request();
            LogWriter::insert(OperationLogModel::class, [
                'user_id' => static::userId() ?? $run->created_user_id ?? 0,
                'path' => substr(admin_base_path(url_join('backup', 'runs', $run->uuid, $event)), 0, 255),
                'method' => $event === 'download' ? 'GET' : 'POST',
                'ip' => isset($request) ? \Exceedone\Exment\Services\LogManagement\LogPrivacy::ip($request->getClientIp()) : '127.0.0.1',
                'input' => json_encode(array_filter([
                    'kind' => $run->kind,
                    'trigger' => $run->triggered_by,
                    'started_at' => $event === 'start' ? $run->started_at?->format('Y-m-d H:i:s') : null,
                    'status' => $event === 'start' ? null : $run->status,
                    'file' => $run->file_name,
                ], fn ($value) => $value !== null)),
            ]);
        } catch (\Throwable $e) {
            // a record that cannot be written does not stop the run
        }
    }

    // ---------------------------------------------------------------- a restore replaces the table

    /**
     * The runs before a restore replaces the database (put back by reinstate()).
     *
     * @return array<int, array<string, mixed>>
     */
    public static function snapshot(): array
    {
        try {
            return \DB::table(BackupRun::TABLE)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
        } catch (\Throwable $e) {
            return [];
        }
    }

    /**
     * After a restore: the runs of the backup that were not over are marked stalled (they were
     * running when the backup was taken), the runs before the restore are put back, and the
     * current one is written.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    public static function reinstate(array $rows, ?BackupRun $current): void
    {
        try {
            // not hasTable(): it keeps what it found, and the restore dropped and made tables
            if (!\Schema::hasTable(BackupRun::TABLE)) {
                return;
            }
            $now = Carbon::now();
            \DB::table(BackupRun::TABLE)->whereIn('status', BackupRun::ACTIVE)->update([
                'status' => BackupRun::STATUS_STALLED,
                'finished_at' => $now,
                'message' => strval(exmtrans('backup.run.message.stalled_restored')),
            ]);
            foreach ($rows as $row) {
                unset($row['id']);
                if (!isset($row['uuid'])) {
                    continue;
                }
                \DB::table(BackupRun::TABLE)->where('uuid', $row['uuid'])->delete();
                \DB::table(BackupRun::TABLE)->insert($row);
            }
            if (isset($current)) {
                $attributes = $current->getAttributes();
                unset($attributes['id']);
                \DB::table(BackupRun::TABLE)->where('uuid', $current->uuid)->delete();
                $id = \DB::table(BackupRun::TABLE)->insertGetId($attributes);
                $current->id = intval($id);
                $current->exists = true;
                $current->syncOriginal();
            }
        } catch (\Throwable $e) {
            \Log::warning('[Exment] backup run, the records after the restore: ' . SecretMasker::mask($e->getMessage()));
        }
    }

    protected static function userId(): ?int
    {
        try {
            $user = \Exment::user();
            return isset($user) ? intval($user->id) : null;
        } catch (\Throwable $e) {
            return null;
        }
    }
}
