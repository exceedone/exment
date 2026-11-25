<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Services\LogManagement\LogWriter;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * The records of the logs of a request, written by the queue (ISS02903, the mode "queue" of
 * LogWriter): the rows are made, and hidden, before they are sent here.
 */
class WriteLogsJob implements ShouldQueue
{
    use JobTrait;

    /**
     * @var array<int, array{0: string|null, 1: string, 2: array<int, array<string, mixed>>}> the connection, the table, the rows
     */
    public $batches;

    /**
     * @param array<int, array{0: string|null, 1: string, 2: array<int, array<string, mixed>>}> $batches
     */
    public function __construct(array $batches)
    {
        $this->batches = $batches;
        // a row that cannot be written goes to the file at once (LogWriter::writeRows): no retry
        $this->tries = 1;
    }

    public function handle(): void
    {
        foreach ($this->batches as [$connection, $table, $rows]) {
            LogWriter::writeRows($connection, $table, $rows);
        }
    }
}
