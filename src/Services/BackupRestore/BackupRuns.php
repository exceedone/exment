<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Carbon\Carbon;
use Exceedone\Exment\Exceptions\BackupFailedException;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupRunLockedException;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Exceptions\RestoreFailedException;
use Exceedone\Exment\Jobs\BackupRunJob;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
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

    /** The name of the backup made before a restore (then the time). */
    public const SAFETY_PREFIX = 'beforerestore';

    /** Where the file of a restore of the screen is (the first of its targets, then the file). */
    public const SOURCE_UPLOAD = 'upload';
    public const SOURCE_STORED = 'stored';
    /** In the targets of a restore of the screen: the data now is backed up first. */
    public const BACKUP_FIRST = 'backup_first';

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

    // ---------------------------------------------------------------- a restore of the screen, apart

    /**
     * Whether a restore of the screen runs in a process of its own (the command), so the request
     * answers at once: PHP may start a command here (not on a shared host without proc_open).
     */
    public static function detachAvailable(): bool
    {
        if (!boolval(config('exment.backup.detach_restore', true))) {
            return false;
        }
        return \Exceedone\Exment\Services\ScheduledJobs\ScheduledJobs::processAvailable();
    }

    /**
     * The command line of a restore apart: fixed, only the uuid of the run in it (no secret, nothing
     * the user wrote). The output goes to a file next to the log.
     */
    public static function restoreCommandLine(BackupRun $run): string
    {
        if (preg_match('/^[0-9a-f\-]{36}$/D', $run->uuid) !== 1) {
            throw new \InvalidArgumentException('the uuid of the run');
        }
        static::ensureLogDirectory();
        // made here, 600, before the command writes to it
        $console = static::consolePath($run);
        @touch($console);
        @chmod($console, 0600);
        $php = \Exceedone\Exment\Services\SystemRequire\Tuning\CliPhp::path();
        $artisan = escapeshellarg(base_path('artisan'));
        $output = escapeshellarg(static::consolePath($run));
        $command = "{$php} {$artisan} exment:restore --run={$run->uuid} --yes";
        if (windows_os()) {
            return 'start /b "" ' . $command . ' >> ' . $output . ' 2>&1';
        }
        return 'umask 077; nohup ' . $command . ' >> ' . $output . ' 2>&1 < /dev/null &';
    }

    /**
     * Start the restore of a queued run in a process of its own, and wait a little for it to take
     * the run. False when it did not: the run is taken back (running), for the caller to run here.
     *
     * @param callable|null $launcher fn (string $line): void (tests)
     */
    public static function launchRestore(BackupRun $run, ?callable $launcher = null): bool
    {
        // its page reads the state from the start
        static::writeStatusFile($run);
        try {
            $line = static::restoreCommandLine($run);
            $launcher = $launcher ?? function (string $line) {
                \Symfony\Component\Process\Process::fromShellCommandline($line, base_path(), null, null, 30)->run();
            };
            $launcher($line);
        } catch (\Throwable $e) {
            \Log::warning('[Exment] backup run, the restore apart did not start: ' . SecretMasker::mask($e->getMessage()));
        }

        $wait = max(0, intval(config('exment.backup.detach_wait_seconds', 10)));
        $until = microtime(true) + $wait;
        do {
            $status = BackupRun::query()->where('uuid', $run->uuid)->value('status');
            if ($status !== BackupRun::STATUS_QUEUED) {
                return true;
            }
            if (microtime(true) >= $until) {
                break;
            }
            usleep(250000);
        } while (true);

        // not taken: the caller runs it (a process that starts later finds it running)
        $taken = BackupRun::query()->where('uuid', $run->uuid)->where('status', BackupRun::STATUS_QUEUED)
            ->update(['status' => BackupRun::STATUS_RUNNING, 'heartbeat_at' => Carbon::now()]);
        if ($taken !== 1) {
            return true;
        }
        $run->refresh();
        return false;
    }

    /**
     * Take a queued restore (the command started apart): false when it is not waiting.
     */
    public static function takeQueued(string $uuid, string $kind): ?BackupRun
    {
        if (preg_match('/^[0-9a-f\-]{36}$/D', $uuid) !== 1) {
            return null;
        }
        $taken = BackupRun::query()->where('uuid', $uuid)->where('kind', $kind)->where('status', BackupRun::STATUS_QUEUED)
            ->update(['status' => BackupRun::STATUS_RUNNING, 'heartbeat_at' => Carbon::now()]);
        if ($taken !== 1) {
            return null;
        }
        /** @var BackupRun|null $run */
        $run = BackupRun::query()->where('uuid', $uuid)->first();
        return $run;
    }

    // ---------------------------------------------------------------- the status file

    /**
     * The status of a run, written next to its log: the screen reads it while a restore has the
     * table of the runs replaced (and the site in maintenance).
     */
    public static function writeStatusFile(BackupRun $run): void
    {
        try {
            static::ensureLogDirectory();
            $path = static::statusPath($run->uuid);
            $temp = $path . '.' . getmypid() . '.tmp';
            if (file_put_contents($temp, strval(json_encode($run->toStatusArray(), JSON_UNESCAPED_UNICODE))) !== false) {
                @chmod($temp, 0600);
                @rename($temp, $path);
            }
        } catch (\Throwable $e) {
            // the status file never stops the run
        }
    }

    /**
     * The status of the status file (null: none, or not a run).
     *
     * @return array<string, mixed>|null
     */
    public static function readStatusFile(string $uuid): ?array
    {
        if (preg_match('/^[0-9a-f\-]{36}$/D', $uuid) !== 1) {
            return null;
        }
        $path = static::statusPath($uuid);
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode(strval(@file_get_contents($path)), true);
        return is_array($data) && ($data['uuid'] ?? null) === $uuid ? $data : null;
    }

    /**
     * The status of a run for the screen: the record, or its status file while it goes on (a
     * restore replaces the record), null when neither is there.
     *
     * @return array<string, mixed>|null
     */
    public static function status(string $uuid): ?array
    {
        $file = static::readStatusFile($uuid);
        try {
            /** @var BackupRun|null $run */
            $run = BackupRun::query()->where('uuid', $uuid)->first();
            if (isset($run) && $run->isActive()) {
                static::markStalled();
                $run->refresh();
            }
        } catch (\Throwable $e) {
            $run = null;
        }
        if (isset($file) && boolval($file['active'] ?? false) && (!isset($run) || $run->isActive())) {
            return $file;
        }
        if (isset($run)) {
            return $run->toStatusArray();
        }
        return $file;
    }

    /**
     * The run going on, for the screen: the record, else a status file written in the last minute
     * (a restore has the table replaced).
     *
     * @return array<string, mixed>|null
     */
    public static function currentStatus(): ?array
    {
        try {
            static::markStalled();
            $run = static::active();
            if (isset($run)) {
                return static::status($run->uuid);
            }
        } catch (\Throwable $e) {
            // the table is being replaced: the files say
        }
        foreach (glob(path_join(storage_path(static::LOG_DIR), '*.json')) ?: [] as $path) {
            if (@filemtime($path) < time() - 60) {
                continue;
            }
            $data = static::readStatusFile(basename($path, '.json'));
            if (isset($data) && boolval($data['active'] ?? false)) {
                return $data;
            }
        }
        return null;
    }

    /**
     * A token for the state of a restore of the screen (BackupRunStateController): its page reads
     * the state while the site is in maintenance, without the session and the database. The hash
     * is kept in a file (600); the token itself goes to the page of the admin only.
     */
    public static function issueStateToken(BackupRun $run): string
    {
        $token = bin2hex(random_bytes(32));
        static::ensureLogDirectory();
        $path = static::tokenPath($run->uuid);
        @touch($path);
        @chmod($path, 0600);
        file_put_contents($path, strval(json_encode(['hash' => hash('sha256', $token), 'expires' => null])));
        return $token;
    }

    /**
     * Whether the run has a token of its state (its file is there): nothing is written for a run
     * without one.
     */
    public static function hasStateToken(string $uuid): bool
    {
        return preg_match('/^[0-9a-f\-]{36}$/D', $uuid) === 1 && is_file(static::tokenPath($uuid));
    }

    /**
     * Whether a token is the one of the state of the run (and not expired).
     */
    public static function checkStateToken(string $uuid, string $token): bool
    {
        if (preg_match('/^[0-9a-f\-]{36}$/D', $uuid) !== 1 || preg_match('/^[0-9a-f]{64}$/D', $token) !== 1) {
            return false;
        }
        $path = static::tokenPath($uuid);
        if (!is_file($path)) {
            return false;
        }
        $data = json_decode(strval(@file_get_contents($path)), true);
        if (!is_array($data) || !is_string($data['hash'] ?? null)) {
            return false;
        }
        if (isset($data['expires']) && intval($data['expires']) < time()) {
            static::forgetStateToken($uuid);
            return false;
        }
        return hash_equals($data['hash'], hash('sha256', $token));
    }

    /**
     * The run ended: its token lasts a few minutes more (the page reads the end), then it is gone.
     */
    public static function expireStateToken(string $uuid): void
    {
        $path = static::tokenPath($uuid);
        if (!is_file($path)) {
            return;
        }
        $data = json_decode(strval(@file_get_contents($path)), true);
        if (!is_array($data)) {
            @unlink($path);
            return;
        }
        $data['expires'] = time() + max(60, intval(config('exment.backup.state_token_seconds', 600)));
        @file_put_contents($path, strval(json_encode($data)));
    }

    public static function tokenPath(string $uuid): string
    {
        return storage_path(path_join(static::LOG_DIR, $uuid . '.token'));
    }

    /**
     * The fields of the state given with the token while the run goes on: the progress only (no
     * text but the fixed labels, no log, no path, no file, no id).
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public static function stateFields(array $status): array
    {
        $keys = ['status', 'active', 'stage', 'stage_label', 'progress', 'stage_done', 'stage_total',
            'started_at', 'finished_at', 'elapsed_seconds', 'remaining_seconds', 'warnings', 'errors'];
        $result = array_intersect_key($status, array_flip($keys));
        $result['stages'] = array_map(function ($stage) {
            return is_array($stage) ? array_intersect_key($stage, array_flip(['name', 'label', 'state', 'seconds', 'count'])) : [];
        }, is_array($status['stages'] ?? null) ? $status['stages'] : []);
        return $result;
    }

    /**
     * The state of a run that ended, given with the token once: its status and its percent only.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public static function endedStateFields(array $status): array
    {
        $done = in_array($status['status'] ?? null, [BackupRun::STATUS_SUCCEEDED, BackupRun::STATUS_WARNING], true);
        return [
            'status' => strval($status['status'] ?? BackupRun::STATUS_FAILED),
            'progress' => $done ? 100 : intval($status['progress'] ?? 0),
        ];
    }

    /**
     * A cheap limit of the reads of the state (a file: no cache, no database, which a restore
     * replaces): exment.backup.state_per_minute (60) a minute for an address and a run.
     */
    public static function stateRateAllowed(string $uuid, string $ip): bool
    {
        $limit = max(1, intval(config('exment.backup.state_per_minute', 60)));
        try {
            static::ensureLogDirectory();
            $path = storage_path(path_join(static::LOG_DIR, $uuid . '.rate'));
            $handle = fopen($path, 'c+');
            if ($handle === false) {
                return true;
            }
            try {
                flock($handle, LOCK_EX);
                $data = json_decode(strval(stream_get_contents($handle)), true);
                $data = is_array($data) ? $data : [];
                $key = hash('sha256', $ip);
                $now = time();
                [$start, $count] = isset($data[$key]) && is_array($data[$key]) ? [intval($data[$key][0] ?? 0), intval($data[$key][1] ?? 0)] : [$now, 0];
                if ($now - $start >= 60) {
                    [$start, $count] = [$now, 0];
                }
                $count++;
                $data[$key] = [$start, $count];
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, strval(json_encode($data)));
                @chmod($path, 0600);
                return $count <= $limit;
            } finally {
                flock($handle, LOCK_UN);
                fclose($handle);
            }
        } catch (\Throwable $e) {
            // the token still guards it
            return true;
        }
    }

    /**
     * The token is used up (the end of the run was read).
     */
    public static function forgetStateToken(string $uuid): void
    {
        foreach ([static::tokenPath($uuid), storage_path(path_join(static::LOG_DIR, $uuid . '.rate'))] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * The restore going on, from the status files only (no database: a restore replaces it): a
     * file of a restore not over, written in the last exment.backup.stale_minutes.
     *
     * @return array<string, mixed>|null
     */
    public static function restoringStatus(): ?array
    {
        $limit = time() - static::staleMinutes() * 60;
        foreach (glob(path_join(storage_path(static::LOG_DIR), '*.json')) ?: [] as $path) {
            if (@filemtime($path) < $limit) {
                continue;
            }
            $data = static::readStatusFile(basename($path, '.json'));
            if (isset($data) && boolval($data['active'] ?? false) && ($data['kind'] ?? null) === BackupRun::KIND_RESTORE) {
                return $data;
            }
        }
        return null;
    }

    public static function statusPath(string $uuid): string
    {
        return storage_path(path_join(static::LOG_DIR, $uuid . '.json'));
    }

    public static function consolePath(BackupRun $run): string
    {
        return storage_path(path_join(static::LOG_DIR, $run->uuid . '.console.log'));
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
    public static function runRestore(BackupRun $run, Restore $restore, ?string $file, ?bool $tmp = null, ?callable $output = null, bool $backupFirst = false): int
    {
        $progress = new RunProgress($run, $output);
        $progress->begin();

        $restore->setProgress($progress);
        $safety = null;
        try {
            if ($backupFirst) {
                $safety = static::backupBeforeRestore($progress, $output);
            }
            try {
                $result = $restore->execute($file, $tmp);
            } catch (RestoreFailedException $e) {
                // stopped midway: the backup made before the restore brings the data back
                if ($e->databaseChanged() && !is_null($safety)) {
                    throw $e->withNote(strval(exmtrans('backup.message.restore_safety_backup', ['name' => $safety])));
                }
                throw $e;
            }
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

    /**
     * A backup of the data now, before a restore replaces it (the targets of the settings). The
     * restore does not start when it fails.
     *
     * @throws \RuntimeException
     */
    protected static function backupBeforeRestore(RunProgress $progress, ?callable $output = null): string
    {
        $progress->stage('safety');
        $name = static::SAFETY_PREFIX . date('YmdHis');
        $backup = new Backup();
        // its own stages are not the restore's: only its output, and its result in the log
        $backup->setProgress(new RunProgress(null, $output));
        $backup->setFileName($name);
        try {
            $result = $backup->execute(System::backup_target());
        } catch (\Throwable $e) {
            $progress->error('the backup before the restore failed: ' . $e->getMessage(), ['exception' => get_class($e)], false);
            throw new \RuntimeException(strval(exmtrans('backup.run.message.safety_failed')), 0, $e);
        }
        if ($result !== 0) {
            $progress->error('the backup before the restore failed', ['result' => $result], false);
            throw new \RuntimeException(strval(exmtrans('backup.run.message.safety_failed')));
        }
        $progress->info('the backup before the restore', ['file' => $name]);
        static::keepSafetyBackups();
        $progress->advance(1, 1);
        return $name;
    }

    /**
     * Keep the last exment.backup.keep_safety backups made before a restore (3).
     */
    public static function keepSafetyBackups(): int
    {
        $keep = max(1, intval(config('exment.backup.keep_safety', 3)));
        try {
            $disk = \Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_BACKUP_SYNC);
            $files = array_values(array_filter($disk->files('list'), function ($file) {
                return (bool) preg_match('/^' . static::SAFETY_PREFIX . '\d{14}\.zip$/', basename($file));
            }));
            // the name holds the time: the newest last
            sort($files);
            $old = array_slice($files, 0, max(0, count($files) - $keep));
            if (count($old) > 0) {
                $disk->delete($old);
            }
            return count($old);
        } catch (\Throwable $e) {
            return 0;
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
        // the class goes to the log only (the screen and the command show the message)
        $progress->error($e->getMessage(), ['exception' => get_class($e)]);
        \Log::error('[Exment] ' . ($progress->run()->kind ?? 'backup') . ' failed: ' . SecretMasker::mask($e->getMessage()));
        $progress->finish(BackupRun::STATUS_FAILED, static::reason($e) . ' ' . strval(exmtrans('backup.run.hints.' . static::hint($e))));
        $run = $progress->run();
        if (isset($run)) {
            static::afterFinish($run);
        }
    }

    protected static function afterFinish(BackupRun $run): void
    {
        static::expireStateToken($run->uuid);
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
        if ($e instanceof BackupRestoreCheckException || $e instanceof BackupFailedException || $e instanceof RestoreFailedException || $e instanceof InvalidZipEntryException || $e instanceof BackupRunLockedException) {
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
        if ($e instanceof BackupRestoreCheckException || $e instanceof BackupFailedException || $e instanceof RestoreFailedException) {
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
            static::writeStatusFile($run);
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
            $path = static::logFullPath($run);
            $new = !file_exists($path);
            file_put_contents($path, sprintf("%s [%s] [%s] %s\n", Carbon::now()->format('Y-m-d H:i:s'), $level, $run->stage ?? '-', SecretMasker::mask($message)), FILE_APPEND | LOCK_EX);
            if ($new) {
                @chmod($path, 0600);
            }
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
        // by the time it was made (a restore puts the runs back with new ids)
        $ids = BackupRun::query()->whereNotIn('status', BackupRun::ACTIVE)->orderByDesc('created_at')->orderByDesc('id')->skip($keep)->take(1000)->pluck('id')->all();
        if (count($ids) === 0) {
            return 0;
        }
        /** @var \Illuminate\Support\Collection<int, BackupRun> $old */
        $old = BackupRun::query()->whereIn('id', $ids)->get();
        foreach ($old as $run) {
            foreach ([static::logFullPath($run), static::statusPath($run->uuid), static::consolePath($run), static::tokenPath($run->uuid), storage_path(path_join(static::LOG_DIR, $run->uuid . '.rate'))] as $path) {
                if (is_file($path)) {
                    @unlink($path);
                }
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
