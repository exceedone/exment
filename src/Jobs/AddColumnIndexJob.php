<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Jobs\Middleware\LogFailureOnSyncDriver;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Services\SystemRequire\Tuning\UnindexedColumnTuning;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * Turn on "search index" of a column chosen on the tuning page (ISS02927): its column and its
 * index are made in the table of the data (CustomColumn::alterColumn), which can take long on a
 * large table. With a queue worker it runs there; without one (the sync driver) after the answer
 * of the request is sent, so the page does not wait.
 */
class AddColumnIndexJob implements ShouldQueue
{
    use JobTrait;

    /**
     * @param string|int|null $userId who chose it (for the log)
     */
    public function __construct(public int $columnId, public $userId = null)
    {
        // a change of the table of the data: once, and it may take long
        $this->tries = 1;
        $this->timeout = 3600;
    }

    /**
     * Start it: on the queue, or after the answer without a worker.
     *
     * @param string|int|null $userId
     */
    public static function start(int $columnId, $userId): void
    {
        if (config('queue.default') === 'sync') {
            static::dispatchAfterResponse($columnId, $userId);
            return;
        }
        static::dispatch($columnId, $userId);
    }

    /**
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new LogFailureOnSyncDriver()];
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();
        if (config('queue.default') === 'sync') {
            @set_time_limit(0);
        }
        try {
            /** @var CustomColumn|null $column */
            $column = CustomColumn::query()->find($this->columnId);
            if (!isset($column) || boolval($column->index_enabled)) {
                return;
            }
            $limit = intval(config('exment.column_index_enabled_count', 20));
            $count = CustomColumn::where('custom_table_id', $column->custom_table_id)->whereIn('options->index_enabled', [1, '1'])->count();
            if ($count >= $limit) {
                Log::warning('[Exment] tuning: the index was not added, the table has all its index columns', ['column' => $this->columnId]);
                return;
            }
            $column->index_enabled = true;
            $column->save();
            Log::info('[Exment] tuning: the index of a column was added', ['column' => $this->columnId, 'user' => $this->userId]);
        } finally {
            $pending = UnindexedColumnTuning::pending();
            unset($pending[$this->columnId]);
            UnindexedColumnTuning::setPending($pending);
        }
    }
}
