<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Services\AuthUserOrgHelper;
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
            // get target date number.
            $before_after_number = intval(array_get($notify->trigger_settings, 'notify_beforeafter'));
            $notify_day = intval(array_get($notify->trigger_settings, 'notify_day'));

            // calc target date
            $target_date = Carbon::today()->addDay($before_after_number * $notify_day * -1);
            $target_date_str = $target_date->format('Y-m-d');

            // get target table and column
            $table = $notify->custom_table;
            $column = CustomColumn::find(array_get($notify, 'trigger_settings.notify_target_column'));

            // find data. where equal target_date
            $datalist = getModelName($table)
                ::where('value->'.$column->column_name, $target_date_str)
                ->get();

            // send mail
            foreach ($datalist as $data) {
                // get user list
                $value_authoritable_users = AuthUserOrgHelper::getAllRoleUserQuery($data)->get();
        
                foreach ($value_authoritable_users as $user) {
                    $prms = [
                        'user' => $user,
                        'notify' => $notify,
                        'target_table' => $table->table_view_name ?? null,
                        'notify_target_column_key' => $column->column_view_name ?? null,
                        'notify_target_column_value' => $data->getValue($column),
                    ];

                    // send mail
                    MailSender::make(array_get($notify->action_settings, 'mail_template_id'), $user->getValue('email'))
                        ->prms($prms)
                        ->custom_value($data)
                        ->send();
                }
            }
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
            if ($last_executed->addDay($term)->today()->gt($now->today())) {
                return;
            }
        }

        // get target
        $target = System::backup_target();
        \Artisan::call('exment:backup', isset($target) ? ['--target' => $target] : []);

        System::backup_automatic_executed($now);
    }
}
