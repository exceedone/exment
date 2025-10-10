<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Jobs\TenantProcessPendingJob;
use Illuminate\Console\Command;

class TenantProcessPendingCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tenant:process-pending {--queue=default : The queue to dispatch the job to}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Process all pending tenants by setting up database and running exment:install';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->info('Dispatching ProcessPendingTenantsJob...');

        try {
            $queue = $this->option('queue');
            
            // Dispatch job to queue
            TenantProcessPendingJob::dispatch()->onQueue($queue);
            
            $this->info("ProcessPendingTenantsJob dispatched to queue: {$queue}");
            $this->info('Check the logs for processing status.');
            
            return 0;

        } catch (\Exception $e) {
            $this->error('Failed to dispatch ProcessPendingTenantsJob: ' . $e->getMessage());
            return 1;
        }
    }
}