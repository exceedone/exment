<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\OperationLog;
use Carbon\Carbon;

/**
 * Clear operation log records.
 * Usage:
 *   php artisan exment:log-clear                  -- delete ALL logs (with confirmation)
 *   php artisan exment:log-clear --keep-days=180  -- delete logs older than 180 days
 *   php artisan exment:log-clear --keep-days=180 --force  -- skip confirmation prompt
 */
class LogClearCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:log-clear
                            {--keep-days= : Delete logs older than this many days}
                            {--force      : Skip confirmation prompt}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete operation log records from admin_operation_log table';

    /**
     * Chunk size for batch deletion on large tables.
     */
    const CHUNK_SIZE = 1000;

    /**
     * Create a new command instance.
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
    public function handle(): int
    {
        $keepDays = $this->option('keep-days');
        $force    = $this->option('force');

        // Validate --keep-days before building threshold
        if (!is_null($keepDays) && (!ctype_digit($keepDays) || (int)$keepDays < 1)) {
            $this->error('--keep-days must be a positive integer (minimum 1).');
            return 1;
        }

        // Build threshold date
        $threshold = $this->resolveThreshold($keepDays);

        // Build confirmation message
        $confirmMessage = $threshold
            ? "This will delete all operation logs created before {$threshold->toDateTimeString()}. Continue?"
            : 'This will delete ALL operation log records. Continue?';

        if (!$force && !$this->confirm($confirmMessage)) {
            $this->info('Aborted.');
            return 0;
        }

        $deleted = $this->deleteChunked($threshold);

        $this->info("Deleted {$deleted} operation log record(s).");
        return 0;
    }

    /**
     * Resolve the threshold Carbon instance from options.
     *
     * @param string|null $keepDays
     * @return Carbon|null  null means "delete everything"
     */
    protected function resolveThreshold(?string $keepDays): ?Carbon
    {
        if (!is_null($keepDays)) {
            return Carbon::now()->subDays((int)$keepDays)->startOfDay();
        }

        return null;
    }

    /**
     * Delete records in chunks to avoid locking the table for too long.
     *
     * @param Carbon|null $threshold
     * @return int total deleted count
     */
    protected function deleteChunked(?Carbon $threshold): int
    {
        $deleted = 0;

        do {
            $query = OperationLog::query();
            if ($threshold) {
                $query->where('created_at', '<', $threshold);
            }

            $ids = $query->orderBy('id')->limit(self::CHUNK_SIZE)->pluck('id');

            if ($ids->isEmpty()) {
                break;
            }

            $count = OperationLog::whereIn('id', $ids)->delete();
            $deleted += $count;
        } while ($count > 0 && $ids->count() === self::CHUNK_SIZE);

        return $deleted;
    }
}
