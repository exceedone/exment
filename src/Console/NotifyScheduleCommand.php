<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;

class NotifyScheduleCommand extends Command
{
    use CommandTrait;
    use NotifyScheduleTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:notifyschedule';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Notify Schedule Batch';

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
        $this->notify();
        return 0;
    }
}
