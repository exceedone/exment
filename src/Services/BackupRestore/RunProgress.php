<?php

namespace Exceedone\Exment\Services\BackupRestore;

use Carbon\Carbon;
use Exceedone\Exment\Model\BackupRun;

/**
 * The progress of a run of the backup or the restore (ISS02943): its stage, its percent, its
 * heartbeat and its detailed log.
 *
 * - The record (BackupRun) is written at most once a second (and at each stage), so a run of
 *   many small files does not write the database for each.
 * - The log is a file under storage (never public), each line masked (SecretMasker), up to
 *   exment.backup.log_max_bytes; then only the warnings and the errors, then nothing.
 * - A command gives an output: the stages and the percent are printed there too.
 * - Without a record (RunProgress::none(), the old callers) nothing is written.
 *
 * A restore replaces the database, the table of the runs with it: the writes in between may find
 * no row, or no table, and are skipped (the record is put back at the end, BackupRuns::reinstate).
 */
class RunProgress
{
    /** @var callable|null fn (string $line): void */
    protected $output;

    protected ?string $logFile = null;

    protected int $logBytes = 0;

    protected bool $logCut = false;

    protected float $lastSaved = 0.0;

    protected float $lastPrinted = 0.0;

    /** @var resource|null the lock of the process (BackupRuns::processLocked) */
    protected $lock = null;

    /** While a restore replaces the database: the record is not written (its id may be another row's). */
    protected bool $suspended = false;

    /** @var array<int, array<string, mixed>> the runs before the database was replaced */
    protected array $snapshot = [];

    public function __construct(protected ?BackupRun $run = null, ?callable $output = null)
    {
        $this->output = $output;
        if (isset($run)) {
            $this->logFile = BackupRuns::logFullPath($run);
        }
    }

    public static function none(): self
    {
        return new self(null);
    }

    public function run(): ?BackupRun
    {
        return $this->run;
    }

    public function setOutput(?callable $output): self
    {
        $this->output = $output;
        return $this;
    }

    // ---------------------------------------------------------------- the run

    /**
     * The run starts in this process.
     */
    public function begin(): void
    {
        if (!isset($this->run)) {
            return;
        }
        $this->lock = BackupRuns::acquireProcessLock();
        $now = Carbon::now();
        $this->run->status = BackupRun::STATUS_RUNNING;
        $this->run->started_at = $this->run->started_at ?? $now;
        $this->run->heartbeat_at = $now;
        $this->run->log_path = BackupRuns::logRelativePath($this->run);
        $this->info('start', [
            'kind' => $this->run->kind,
            'trigger' => $this->run->triggered_by,
            'targets' => implode(',', $this->run->targets ?? []),
            'file' => $this->run->file_name,
            'exment' => \Exment::getExmentCurrentVersion(),
            'php' => PHP_VERSION,
        ]);
        $this->save(true);
    }

    /**
     * A new stage: the stage before ends.
     */
    public function stage(string $name, int $total = 0): void
    {
        $this->closeStage();
        if (!isset($this->run)) {
            $this->print(strval(exmtrans("backup.run.stages.{$name}")));
            return;
        }
        $stages = $this->run->stages ?? [];
        $stages[] = [
            'name' => $name,
            'started_at' => Carbon::now()->format('Y-m-d H:i:s'),
            'finished_at' => null,
            'seconds' => null,
            'count' => 0,
            'bytes' => 0,
            'warnings' => 0,
            'errors' => 0,
        ];
        $this->run->stages = $stages;
        $this->run->stage = $name;
        $this->run->stage_done = 0;
        $this->run->stage_total = max(0, $total);
        $this->run->progress = $this->computeProgress();

        $keys = array_keys($this->run->stageWeights());
        $index = array_search($name, $keys, true);
        $this->print(sprintf('[%d/%d] %s', ($index === false ? 0 : $index) + 1, count($keys), strval(exmtrans("backup.run.stages.{$name}"))));
        $this->log('INFO', 'stage start', ['total' => $total]);
        $this->save(true);
    }

    /**
     * The units (bytes or items) of the current stage, when they are known after it started.
     */
    public function total(int $total): void
    {
        if (!isset($this->run)) {
            return;
        }
        $this->run->stage_total = max(0, $total);
        $this->run->progress = $this->computeProgress();
        $this->save();
    }

    /**
     * Some of the current stage is done: $units of the total, $count items, $bytes bytes.
     */
    public function advance(int $units = 1, int $count = 0, int $bytes = 0): void
    {
        if (!isset($this->run)) {
            return;
        }
        $this->run->stage_done = $this->run->stage_done + max(0, $units);
        $this->updateStageRow(function (array $row) use ($count, $bytes) {
            $row['count'] = intval($row['count'] ?? 0) + $count;
            $row['bytes'] = intval($row['bytes'] ?? 0) + $bytes;
            return $row;
        });
        $this->run->progress = $this->computeProgress();
        $this->save();
        $this->printProgress();
    }

    /**
     * The run is alive (a long step without units).
     */
    public function heartbeat(): void
    {
        $this->save();
    }

    /**
     * The run ends.
     */
    public function finish(string $status, ?string $message = null): void
    {
        $this->closeStage();
        if (!isset($this->run)) {
            return;
        }
        $this->run->status = $status;
        $this->run->message = isset($message) ? mb_substr(SecretMasker::mask($message), 0, 2000) : null;
        $this->run->finished_at = Carbon::now();
        if ($status === BackupRun::STATUS_SUCCEEDED || $status === BackupRun::STATUS_WARNING) {
            $this->run->progress = 100;
        }
        $this->log($status === BackupRun::STATUS_FAILED ? 'ERROR' : 'INFO', 'finish', [
            'status' => $status,
            'seconds' => $this->run->elapsedSeconds(),
            'warnings' => $this->run->warnings,
            'errors' => $this->run->errors,
            'file_size' => $this->run->file_size,
            'message' => $this->run->message,
        ]);
        $this->print(sprintf('%s (%s)', strval(exmtrans("backup.run.statuses.{$status}")), static::duration($this->run->elapsedSeconds() ?? 0)));
        $this->save(true);
        $this->releaseLock();
    }

    /**
     * A restore starts to replace the database: keep the runs, stop writing the record.
     */
    public function databaseReplacing(): void
    {
        if (!isset($this->run) || $this->suspended) {
            return;
        }
        $this->save(true);
        $this->snapshot = BackupRuns::snapshot();
        $this->suspended = true;
    }

    /**
     * The database is replaced (and migrated): put the runs back, write the record again.
     */
    public function databaseReplaced(): void
    {
        if (!isset($this->run) || !$this->suspended) {
            return;
        }
        $this->suspended = false;
        BackupRuns::reinstate($this->snapshot, $this->run);
        $this->snapshot = [];
        $this->save(true);
    }

    public function releaseLock(): void
    {
        BackupRuns::releaseProcessLock($this->lock);
        $this->lock = null;
    }

    // ---------------------------------------------------------------- the log

    /**
     * @param array<string, mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('INFO', $message, $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    public function warn(string $message, array $context = []): void
    {
        $this->log('WARNING', $message, $context);
        $this->print('  ' . strval(exmtrans('backup.run.warning')) . ': ' . SecretMasker::mask($message));
        if (!isset($this->run)) {
            return;
        }
        $this->run->warnings = $this->run->warnings + 1;
        $this->updateStageRow(function (array $row) {
            $row['warnings'] = intval($row['warnings'] ?? 0) + 1;
            return $row;
        });
        $this->save();
    }

    /**
     * @param array<string, mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('ERROR', $message, $context);
        $this->print('  ' . strval(exmtrans('backup.run.error')) . ': ' . SecretMasker::mask($message));
        if (!isset($this->run)) {
            return;
        }
        $this->run->errors = $this->run->errors + 1;
        $this->updateStageRow(function (array $row) {
            $row['errors'] = intval($row['errors'] ?? 0) + 1;
            return $row;
        });
        $this->save();
    }

    /**
     * A line of the log, masked.
     *
     * @param array<string, mixed> $context
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (!isset($this->logFile)) {
            return;
        }
        $max = max(10240, intval(config('exment.backup.log_max_bytes', 1048576)));
        $important = $level !== 'INFO';
        if ($this->logBytes >= $max && (!$important || $this->logBytes >= $max + 65536)) {
            if (!$this->logCut) {
                $this->logCut = true;
                $this->write(sprintf("%s [INFO] [-] the log is cut here (%d bytes); the warnings and the errors go on\n", Carbon::now()->format('Y-m-d H:i:s'), $max));
            }
            return;
        }
        $stage = isset($this->run) ? ($this->run->stage ?? '-') : '-';
        $line = sprintf('%s [%s] [%s] %s', Carbon::now()->format('Y-m-d H:i:s'), $level, $stage, SecretMasker::mask($message));
        $context = array_filter(SecretMasker::maskArray($context), fn ($value) => $value !== null && $value !== '');
        if (count($context) > 0) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        }
        // one line each: a message of many lines is kept on one
        $line = str_replace(["\r\n", "\r", "\n"], ' | ', $line) . "\n";
        $this->write($line);
    }

    protected function write(string $line): void
    {
        if (!isset($this->logFile)) {
            return;
        }
        try {
            BackupRuns::ensureLogDirectory();
            $new = !file_exists($this->logFile);
            if (file_put_contents($this->logFile, $line, FILE_APPEND | LOCK_EX) !== false) {
                $this->logBytes += strlen($line);
                if ($new) {
                    @chmod($this->logFile, 0600);
                }
            }
        } catch (\Throwable $e) {
            // the log never stops the run
        }
    }

    // ---------------------------------------------------------------- inside

    protected function closeStage(): void
    {
        if (!isset($this->run) || !isset($this->run->stage)) {
            return;
        }
        $closed = false;
        $this->updateStageRow(function (array $row) use (&$closed) {
            if (isset($row['finished_at'])) {
                return $row;
            }
            $now = Carbon::now();
            $row['finished_at'] = $now->format('Y-m-d H:i:s');
            $row['seconds'] = isset($row['started_at']) ? max(0, (int) Carbon::parse(strval($row['started_at']))->diffInSeconds($now, true)) : null;
            $closed = true;
            return $row;
        });
        if ($closed) {
            $row = $this->run->stageRow($this->run->stage) ?? [];
            $this->log('INFO', 'stage end', [
                'seconds' => $row['seconds'] ?? null,
                'count' => $row['count'] ?? null,
                'bytes' => $row['bytes'] ?? null,
                'warnings' => $row['warnings'] ?? null,
                'errors' => $row['errors'] ?? null,
            ]);
        }
        $this->run->stage_done = $this->run->stage_total;
        $this->run->progress = $this->computeProgress();
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $callback
     */
    protected function updateStageRow(callable $callback): void
    {
        if (!isset($this->run) || !isset($this->run->stage)) {
            return;
        }
        $stages = $this->run->stages ?? [];
        for ($i = count($stages) - 1; $i >= 0; $i--) {
            if (($stages[$i]['name'] ?? null) === $this->run->stage) {
                $stages[$i] = $callback($stages[$i]);
                break;
            }
        }
        $this->run->stages = $stages;
    }

    /**
     * The whole percent: the stages done, and the part of the current one (99 at most until the end).
     */
    protected function computeProgress(): int
    {
        if (!isset($this->run)) {
            return 0;
        }
        $weights = $this->run->stageWeights();
        $sum = array_sum($weights);
        if ($sum <= 0) {
            return 0;
        }
        $done = 0.0;
        foreach ($weights as $name => $weight) {
            $row = $this->run->stageRow($name);
            if (isset($row['finished_at'])) {
                $done += $weight;
            } elseif ($name === $this->run->stage) {
                $total = $this->run->stage_total;
                $part = $total > 0 ? min(1.0, $this->run->stage_done / $total) : 0.0;
                $done += $weight * $part;
            }
        }

        return (int) min(99, floor($done * 100 / $sum));
    }

    /**
     * Write the record (at most once a second, unless $force).
     */
    protected function save(bool $force = false): void
    {
        if (!isset($this->run)) {
            return;
        }
        $now = microtime(true);
        if (!$force && $now - $this->lastSaved < floatval(config('exment.backup.save_interval', 1.0))) {
            return;
        }
        $this->lastSaved = $now;
        $this->run->heartbeat_at = Carbon::now();
        // the status for the screen, also while a restore replaces the table (the screen polls it)
        BackupRuns::writeStatusFile($this->run);
        if ($this->suspended) {
            return;
        }
        try {
            $this->run->save();
        } catch (\Throwable $e) {
            // a restore is replacing the table: the record is put back at the end
        }
    }

    protected function print(string $line): void
    {
        if (!isset($this->output)) {
            return;
        }
        try {
            ($this->output)($line);
        } catch (\Throwable $e) {
            // the output never stops the run
        }
    }

    protected function printProgress(): void
    {
        if (!isset($this->output) || !isset($this->run)) {
            return;
        }
        $now = microtime(true);
        if ($now - $this->lastPrinted < 2.0) {
            return;
        }
        $this->lastPrinted = $now;
        $line = sprintf('  %3d%%  %s', $this->run->progress, strval(exmtrans("backup.run.stages.{$this->run->stage}")));
        if ($this->run->stage_total > 0) {
            $line .= sprintf('  %s / %s', number_format($this->run->stage_done), number_format($this->run->stage_total));
        }
        $remaining = $this->run->remainingSeconds();
        if ($remaining !== null) {
            $line .= '  ' . strval(exmtrans('backup.run.remaining')) . ' ' . static::duration($remaining);
        }
        $this->print($line);
    }

    public static function duration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . 's';
        }
        if ($seconds < 3600) {
            return sprintf('%dm%02ds', intdiv($seconds, 60), $seconds % 60);
        }
        return sprintf('%dh%02dm', intdiv($seconds, 3600), intdiv($seconds % 3600, 60));
    }
}
