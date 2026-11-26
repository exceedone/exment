<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\TrashPurge;
use Illuminate\Console\Command;

/**
 * Delete for good the deleted data older than the days of its table, after writing it to the
 * archive (deleted_archives), and remove the archives past their days (ISS00147).
 * Runs every night from the schedule.
 *
 *   php artisan exment:trash-purge
 *   php artisan exment:trash-purge --dry-run            -- only count
 *   php artisan exment:trash-purge --table=table_name   -- only this table
 */
class TrashPurgeCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:trash-purge
                            {--dry-run : Only count, change nothing}
                            {--table= : Only this table}';

    /**
     * @var string
     */
    protected $description = 'Delete for good the deleted data older than the days of its table, after keeping it in the archive.';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $dryRun = boolval($this->option('dry-run'));
        $tableName = $this->option('table');
        $tableName = is_nullorempty($tableName) ? null : strval($tableName);

        $total = ['target' => 0, 'purged' => 0, 'skipped' => 0, 'skipped_children' => 0];
        foreach (TrashPurge::tables($tableName) as $table) {
            $result = TrashPurge::purgeTable($table, $dryRun);
            foreach ($total as $key => $count) {
                $total[$key] = $count + ($result[$key] ?? 0);
            }
            if ($result['target'] > 0) {
                $this->line($table->table_name . ': ' . static::counts($result, $dryRun));
            }
        }

        $archives = is_null($tableName) ? TrashPurge::removeExpired($dryRun) : 0;

        $message = $dryRun
            ? "[dry-run] trash purge: " . static::counts($total, true) . ", would remove archives {$archives}"
            : "trash purge: " . static::counts($total, false) . ", archives removed {$archives}";
        $this->info($message);
        if (!$dryRun && ($total['target'] > 0 || $archives > 0)) {
            \Log::info('[Exment] ' . $message);
        }
        return 0;
    }

    /**
     * @param array<string, int> $result
     */
    protected static function counts(array $result, bool $dryRun): string
    {
        if ($dryRun) {
            return "would purge {$result['purged']}, would skip {$result['skipped']} (children not deleted: " . ($result['skipped_children'] ?? 0) . ')';
        }
        return "target {$result['target']}, purged {$result['purged']}, skipped {$result['skipped']}";
    }
}
