<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\NotifyTrigger;
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
     * @return void
     */
    public function handle()
    {
        $this->notify();
        $this->backup();
        $this->pluginBatch();
    }

    /**
     * notify user flow
     */
    protected function notify()
    {
        // get notifies data for notify_trigger is 1(time), and notify_hour is executed time
        $hh = Carbon::now()->format('G');
        $notifies = Notify::where('notify_trigger', NotifyTrigger::TIME)
            ->where('trigger_settings->notify_hour', $hh)
            ->where('active_flg', 1)
            ->get();

        // loop for $notifies
        foreach ($notifies as $notify) {
            $notify->notifySchedule();
        }
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
}
