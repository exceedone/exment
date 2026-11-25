<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Illuminate\Console\Command;

/**
 * Delete the logs older than they are kept (ISS02839): each kind of Services\LogManagement\
 * LogCatalog that this job deletes (the operation log is deleted by exment:schedule, as the
 * system setting says). Scheduled every day; on the page "Scheduled jobs" too.
 */
class LogMaintenanceCommand extends Command
{
    use CommandTrait;

    protected $signature = 'exment:log-maintenance';

    protected $description = 'Delete the logs older than the log management keeps them';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        foreach (LogCatalog::prune() as $key => $count) {
            $this->info("{$key}: {$count} deleted");
        }
        return 0;
    }
}
