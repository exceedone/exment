<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\SlaService;

/**
 * Recalculate SLA deadlines and escalate what has run out.
 *
 * Runs from the scheduler, and by hand when you want to see it work:
 *
 *   php artisan exment:sla
 *   php artisan exment:sla --table=incident
 *   php artisan exment:sla --dry
 */
class SlaCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:sla {--table= : only this table} {--dry : report without writing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Recalculate SLA deadlines and escalate breached records';

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
    public function handle()
    {
        $service = new SlaService();
        $results = $service->run($this->option('table') ?: null, !$this->option('dry'));

        if (empty($results)) {
            $this->warn('no table has an SLA setting');
        } else {
            $this->table(
                ['table', 'records', 'clock running', 'warning', 'no response', 'breached', 'escalated', 'no policy'],
                collect($results)->map(function ($counters, $table) {
                    return [
                        $table,
                        $counters['records'],
                        $counters['clocked'],
                        $counters['warned'],
                        $counters['response'],
                        $counters['breached'],
                        $counters['escalated'],
                        $counters['nopolicy'],
                    ];
                })->values()->toArray()
            );
        }

        foreach ($service->notes() as $note) {
            $this->warn($note);
        }

        if ($this->option('dry')) {
            $this->info('dry run - nothing written');
        }

        return 0;
    }
}
