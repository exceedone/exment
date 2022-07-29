<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Plugin;
use Carbon\Carbon;

class ScheduleCommand extends Command
{
    use CommandTrait;
    use NotifyScheduleTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:schedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Schedule Batch';

    /**
     * Create a new command instance.
     *
     * @return void
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
    public function handle()
    {
        $this->debugLog('Exment schedule command called.');
        $this->notify();
        $this->backup();
        $this->pluginBatch();
        return 0;
    }

    protected function backup()
    {
        if (!boolval(System::backup_enable_automatic())) {
            return;
        }

        $now = Carbon::now();
        $hh = $now->hour;
        if ($hh != System::backup_automatic_hour()) {
            return;
        }

        // set date as minute and second is 0
        $nowHour = Carbon::create($now->year, $now->month, $now->day, $now->hour, 0, 0);

        $last_executed = System::backup_automatic_executed();
        if (!is_nullorempty($last_executed)) {
            $term = System::backup_automatic_term();
            // set date as minute and second is 0
            $last_executed = Carbon::create($last_executed->year, $last_executed->month, $last_executed->day + $term, $last_executed->hour, 0, 0);
            if ($last_executed->gt($nowHour)) {
                return;
            }
        }

        // get target
        $target = System::backup_target();
        \Artisan::call('exment:backup', !is_nullorempty($target) ? ['--target' => $target, '--schedule' => 1] : []);

        System::backup_automatic_executed($now);
    }

    /**
     * Execute Plugin Batch
     *
     * @return void
     */
    protected function pluginBatch()
    {
        $pluginBatches = Plugin::getBatches();

        foreach ($pluginBatches as $pluginBatch) {
            \Artisan::call("exment:batch", ['--uuid' => $pluginBatch->uuid]);
        }
    }


    protected function debugLog(string $log)
    {
        if (!boolval(config('exment.debugmode_schedule', false))) {
            return;
        }

        \Log::debug($log);
    }
}
