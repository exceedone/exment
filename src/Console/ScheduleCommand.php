<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\NotifyTrigger;
use Carbon\Carbon;

class ScheduleCommand extends Command
{
    use CommandTrait;

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
            ->get();

        // loop for $notifies
        foreach ($notifies as $notify) {
            $notify->notifyUser();
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

        $last_executed = System::backup_automatic_executed();
        if (isset($last_executed)) {
            $term = System::backup_automatic_term();
            if ($last_executed->addDay($term)->gt($now->today())) {
                return;
            }
        }

        // get target
        $target = System::backup_target();
        \Artisan::call('exment:backup', isset($target) ? ['--target' => $target] : []);

        System::backup_automatic_executed($now);
    }
}
