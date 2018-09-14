<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Carbon\Carbon;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Relations\Relation;
use Exceedone\Exment\Model;
use Exceedone\Exment\Services\MailSender;

class NotifyCommand extends CommandBase
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:notify';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify limit';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        parent::handle();
        
        // get notifies data for notify_trigger is 1(time), and notify_hour is executed time
        $hh = Carbon::now()->format('G');
        $notifies = Notify::where('notify_trigger', '1')
            ->where('trigger_settings->notify_hour', $hh)
            ->get();

        // loop for $notifies
        foreach($notifies as $notify){
            // get target date number.
            $before_after_number = intval(array_get($notify->trigger_settings, 'notify_beforeafter'));
            $notify_day = intval(array_get($notify->trigger_settings, 'notify_day'));

            // calc target date
            $target_date = Carbon::today()->addDay($before_after_number * $notify_day * -1);
            $target_date_str = $target_date->format('Y-m-d');

            // get target table and column
            $table = CustomTable::find(array_get($notify, 'custom_table_id'));
            $column = CustomColumn::find(array_get($notify->trigger_settings, 'notify_target_column'));
            

            // find data. where equal target_date
            $datalist = getModelName(array_get($notify, 'custom_table_id'))
                ::where('value->'.$column->column_name, $target_date_str)
                ->get();

            // send mail
            foreach($datalist as $data){
                // get user list
                $value_authoritable_users = $data->value_authoritable_users->toArray();
                
                // get organization 
                if (System::organization_available()) {
                    $value_authoritable_organizations = System::organization_available() ? $data->value_authoritable_organizations : [];
                    foreach($value_authoritable_organizations as $value_authoritable_organization){
                        $children_users = getChildrenValues($value_authoritable_organization, Define::SYSTEM_TABLE_NAME_USER)->toArray();
                        //$value_authoritable_users[] = 

                        $value_authoritable_users = array_merge($value_authoritable_users, $children_users);
                    }
                }
                
                foreach($value_authoritable_users as $user){
                    $notify_target_table = CustomTable::find($notify->custom_table_id);
                    $notify_target_column = CustomColumn::find(array_get($notify->toArray(), 'trigger_settings.notify_target_column'));
                    $prms = [
                        'user' => $user,
                        'notify' => $notify->toArray(),
                        'target_table' => $notify_target_table->table_view_name,
                        'target_value' => getValue($data, null, true),
                        'notify_target_column_key' => $notify_target_column->column_view_name,
                        'notify_target_column_value' => getValue($data, $notify_target_column->column_name),
                        'data_url' => admin_url(url_join("data", $notify_target_table->table_name, $data->id)),
                    ];

                    // send mail
                    MailSender::make(array_get($notify->action_settings, 'mail_template_id'), array_get($user, 'value.email'))
                    ->prms($prms)
                    ->send();
                }
            }
        }
    }
}
