<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Jobs\Middleware\LogFailureOnSyncDriver;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\Encryption\ColumnConverter;
use Exceedone\Exment\Services\Encryption\EncryptedColumns;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;

/**
 * The values already there of a column encrypted (or decrypted) after its option "encrypted"
 * was turned on (or off) (ISS00148). A part (ColumnConverter::CHUNK rows) a run, then the next
 * part as a new job, so a worker is not held. It stops when the option was turned the other way
 * meanwhile: the job of that change converts the values back.
 *
 * Without a queue worker (the sync driver) it runs inside the request that saved the column;
 * a large table is better converted with php artisan exment:encrypt-column.
 */
class EncryptColumnJob implements ShouldQueue
{
    use JobTrait;

    public int $backoff = 10;

    public function __construct(public int $columnId, public bool $encrypt, public string $phase = ColumnConverter::PHASE_ROWS, public int $after = 0)
    {
        $this->afterCommit();
    }

    public function middleware(): array
    {
        return [new LogFailureOnSyncDriver()];
    }

    public function handle(): void
    {
        $this->resetRequestSessionOnWorker();

        /** @var CustomColumn|null $column */
        $column = CustomColumn::query()->find($this->columnId);
        $table = isset($column) ? CustomTable::getEloquent($column->custom_table_id) : null;
        if (!isset($column, $table) || EncryptedColumns::isEncrypted($column) !== $this->encrypt) {
            return;
        }

        $step = ColumnConverter::step($table, $column->column_name, $this->encrypt, $this->phase, $this->after);
        if ($step['unreadable'] > 0) {
            Log::warning('[Exment] encrypted values without their key were left as they are', [
                'table' => $table->table_name, 'column' => $column->column_name, 'count' => $step['unreadable'],
            ]);
        }
        if (!$step['done']) {
            static::dispatch($this->columnId, $this->encrypt, $step['phase'], $step['after']);
        }
    }
}
