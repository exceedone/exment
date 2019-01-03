<?php

namespace Exceedone\Exment\Model;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Console\Command;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Services\AuthUserOrgHelper;
use Carbon\Carbon;

class Notify extends ModelBase
{
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use \Illuminate\Database\Eloquent\SoftDeletes;

    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function getTriggerSetting($key, $default = null)
    {
        return $this->getJson('trigger_settings', $key, $default);
    }

    public function getActionSetting($key, $default = null)
    {
        return $this->getJson('action_settings', $key, $default);
    }

    /**
     * notify user
     */
    public function notifyUser(){
        list($datalist, $table, $column) = $this->getNotifyTargetDatalist();

        // loop data
        foreach ($datalist as $data) {
            $users = $this->getNotifyTargetUsers($data);
            foreach ($users as $user) {
                $prms = [
                    'user' => $user,
                    'notify' => $this,
                    'target_table' => $table->table_view_name ?? null,
                    'notify_target_column_key' => $column->column_view_name ?? null,
                    'notify_target_column_value' => $data->getValue($column),
                ];

                // send mail
                MailSender::make(array_get($this->action_settings, 'mail_template_id'), $user->getValue('email'))
                    ->prms($prms)
                    ->user($user)
                    ->custom_value($data)
                    ->send();
            }
        }
    }
    
    /**
     * notify_create_update_user
     */
    public function notifyCreateUpdateUser($data, $create = true){
        $custom_table = $data->custom_table;
        $mail_send_history_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_HISTORY);
        $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', MailKeyName::DATA_SAVED_NOTIFY)->first();

        // loop data
        $users = $this->getNotifyTargetUsers($data);
        foreach ($users as $user) {
            if(!$this->approvalSendUser($mail_template, $custom_table, $data, $user)){
                continue;
            }

            $prms = [
                'user' => $user,
                'notify' => $this,
                'target_table' => $custom_table->table_view_name ?? null,
                'create_or_update' => $create ? exmtrans('common.created') : exmtrans('common.updated')
            ];

            // send mail
            MailSender::make($mail_template, $user)
                ->prms($prms)
                ->user($user)
                ->custom_value($data)
                ->send();
        }
    }
    
    /**
     * get notify target datalist
     */
    protected function getNotifyTargetDatalist()
    {
        // get target date number.
        $before_after_number = intval(array_get($this->trigger_settings, 'notify_beforeafter'));
        $notify_day = intval(array_get($this->trigger_settings, 'notify_day'));

        // calc target date
        $target_date = Carbon::today()->addDay($before_after_number * $notify_day * -1);
        $target_date_str = $target_date->format('Y-m-d');

        // get target table and column
        $table = $this->custom_table;
        $column = CustomColumn::find(array_get($this, 'trigger_settings.notify_target_column'));

        // find data. where equal target_date
        $datalist = getModelName($table)
            ::where('value->'.$column->column_name, $target_date_str)
            ->get();

        return [$datalist, $table, $column];
    }
        
    /**
     * get notify target users
     */
    protected function getNotifyTargetUsers($data)
    {
        $notify_action_target = $this->getActionSetting('notify_action_target');
        if(!isset($notify_action_target)){
            return [];
        }

        // if has_roles, return has permission users
        if($notify_action_target == NotifyActionTarget::HAS_ROLES){
            return AuthUserOrgHelper::getAllRoleUserQuery($data)->get();
        }

        $users = $data->getValue($notify_action_target);
        if(is_null($users)){
            return [];
        }
        if(!($users instanceof Collection)){
            $users = collect([$users]);
        }
        
        return $users;
    }

    /**
     * 
     */
    protected function approvalSendUser($mail_template, $custom_table, $data, $user){
        $mail_send_history_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_HISTORY);

        // if already send notify in 1 minutes, continue.
        $index_user = CustomColumn::getEloquent('user', $mail_send_history_table)->getIndexColumnName();
        $index_mail_template = CustomColumn::getEloquent('mail_template', $mail_send_history_table)->getIndexColumnName();
        $mail_send_histories = getModelName(SystemTableName::MAIL_SEND_HISTORY)
            ::where($index_user, $user->id)
            ->where($index_mail_template, $mail_template->id)
            ->where('parent_id', $data->id)
            ->where('parent_type', $custom_table->table_name)
            ->get()
        ;
        foreach($mail_send_histories as $mail_send_history){
            $send_datetime = (new Carbon($mail_send_history->getValue('send_datetime')))->addMinutes(5);
            $now = Carbon::now();
            // If user were sending within 5 minutes,
            if($send_datetime->gt($now)){
                return false;
            }
        }

        return true;
    }
}
