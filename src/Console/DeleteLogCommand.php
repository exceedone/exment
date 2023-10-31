<?php

namespace Exceedone\Exment\Console;

use Carbon\Carbon;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\System;
use Illuminate\Console\Command;

class DeleteLogCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:deletelog';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete log of file';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $this->deleteLogs();
        return 0;
    }

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
     * Delete log of file
     *
     * @return void
     */
    protected function deleteLogs()
    {
        $log_available = System::log_available();
        $time_delete_log = System::time_clear_log();
        $time_delete_log_unit = System::time_clear_log_unit();
        if ($log_available) {
            $range_time = null;
            switch ($time_delete_log_unit) {
                case 'day':
                    $range_time = Carbon::now()->subDay($time_delete_log);
                    break;
                case 'month':
                    $range_time = Carbon::now()->subMonth($time_delete_log);
                    break;
                case 'year':
                    $range_time = Carbon::now()->subYear($time_delete_log);
                    break;
                default:
                    break;
            }
            if ($range_time) {
                CustomTable::getEloquent(SystemTableName::ACCESS_FILE_LOG)->getValueModel()
                    ->where('created_at', '<', $range_time)
                    ->forceDelete();
            }
        }
    }
}
