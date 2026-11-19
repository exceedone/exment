<?php

namespace Exceedone\Exment\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Foundation\Bus\Dispatchable;

trait JobTrait
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Default try max count
     *
     * @var int
     */
    public $tries = 3;

    /**
     * Job timeout seconds
     *
     * @var int
     */
    public $timeout = 120;

    /**
     * Drop Exment's in-memory cache (table/column definitions, linked records) kept
     * by a long-running worker. Skipped on the sync driver, which runs inside the caller's request.
     */
    protected function resetRequestSessionOnWorker(): void
    {
        if ($this->job === null || $this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
            return;
        }
        \Exceedone\Exment\Model\System::clearRequestSession();
    }
}
