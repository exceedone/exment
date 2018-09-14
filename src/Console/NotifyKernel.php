<?php
namespace Exceedone\Exment\Console;

use App\Console\Kernel as ConsoleKernel;
use Illuminate\Console\Scheduling\Schedule;

class NotifyKernel extends ConsoleKernel
{
    protected $commands = [
        \Exceedone\Exment\Console\NotifyCommand::class,
    ];

        /**
     * Define the package's command schedule.
     *
     * @param  \Illuminate\Console\Scheduling\Schedule  $schedule
     * @return void
     */
    protected function schedule(Schedule $schedule)
    {   
        $schedule->command('exment:notify')->hourlyAt(0);
    }
}