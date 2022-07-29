<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Enums\NotifyTrigger;

class NotifyCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:notify {id?} {--name=} {--suuid=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Execute Notify Batch';

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
        $notify = $this->findNotify();

        if (!isset($notify)) {
            return 0;
        }

        $notify->notifySchedule();
        return 0;
    }

    protected function findNotify(): ?Notify
    {
        $query = Notify::where('notify_trigger', NotifyTrigger::TIME);
        // Execute batch. *Batch can execute if active_flg is false, so get value directly.
        if (!is_null($key = $this->argument("id"))) {
            $query->where('id', $key);
        } elseif (!is_null($key = $this->option("name"))) {
            $query->where('notify_name', $key);
            $notify = Notify::where('notify_name', $key)->first();
        } elseif (!is_null($key = $this->option("suuid"))) {
            $query->where('suuid', $key);
        } else {
            $this->error('Please input id, name, or suuid.');
            return null;
        }

        $result = $query->first();
        if (!$result) {
            $this->error('Notify time not found.');
        }

        return $result;
    }
}
