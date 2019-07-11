<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Services\MailSender;
use Carbon\Carbon;

class Notify extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;

    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }

    public function getMailTemplate()
    {
        $mail_template_id = array_get($this->action_settings, 'mail_template_id');

        if (isset($mail_template_id)) {
            return getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_template_id);
        }
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
    public function notifyUser()
    {
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
                try {
                    MailSender::make(array_get($this->action_settings, 'mail_template_id'), $user)
                    ->prms($prms)
                    ->user($user)
                    ->custom_value($data)
                    ->send();
                }
                // throw mailsend Exception
                catch (\Swift_TransportException $ex) {
                    // TODO:loging error
                }
            }
        }
    }
    
    /**
     * notify_create_update_user
     */
    public function notifyCreateUpdateUser($data, $create = true)
    {
        $custom_table = $data->custom_table;
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);
        $mail_template = $this->getMailTemplate();

        // loop data
        $users = $this->getNotifyTargetUsers($data);
        
        foreach ($users as $user) {
            if (!$this->approvalSendUser($mail_template, $custom_table, $data, $user)) {
                continue;
            }

            $prms = [
                'user' => $user,
                'notify' => $this,
                'target_table' => $custom_table->table_view_name ?? null,
                'create_or_update' => $create ? exmtrans('common.created') : exmtrans('common.updated')
            ];

            // send mail
            try {
                MailSender::make($mail_template, $user)
                ->prms($prms)
                ->user($user)
                ->custom_value($data)
                ->send();
            }
            // throw mailsend Exception
            catch (\Swift_TransportException $ex) {
                // show warning message
                admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
            }
        }
    }
    
    /**
     * notify_create_update_user
     */
    public function notifyButtonClick($custom_value, $target_user_keys, $subject, $body, $attachments = [])
    {
        $custom_table = $custom_value->custom_table;
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);
        $mail_template = $this->getMailTemplate();
        $attach_files = collect($attachments)->map(function ($uuid) {
            return File::where('uuid', $uuid)->first();
        })->filter();

        // loop target users
        foreach ($target_user_keys as $target_user_key) {
            $user = NotifyTarget::getSelectedNotifyTarget($target_user_key, $this, $custom_value);
            if (!isset($user)) {
                continue;
            }

            if (!$this->approvalSendUser($mail_template, $custom_table, $custom_value, $user, false)) {
                continue;
            }

            $prms = [
                'user' => $user,
                'notify' => $this,
                'target_table' => $custom_table->table_view_name ?? null
            ];

            // send mail
            try {
                MailSender::make($mail_template, $user)
                ->prms($prms)
                ->user($user)
                ->custom_value($custom_value)
                ->subject($subject)
                ->body($body)
                ->attachments($attach_files)
                ->send();
            }
            // throw mailsend Exception
            catch (\Swift_TransportException $ex) {
                // show warning message
                admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
            }
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
        $column = CustomColumn::getEloquent(array_get($this, 'trigger_settings.notify_target_column'));

        // find data. where equal target_date
        $datalist = getModelName($table)
            ::where('value->'.$column->column_name, $target_date_str)
            ->get();

        return [$datalist, $table, $column];
    }
       
    /**
     * get notify target users
     *
     * @param CustomValue $custom_value target custom value
     * @return void
     */
    public function getNotifyTargetUsers($custom_value)
    {
        $notify_action_target = $this->getActionSetting('notify_action_target');
        if (!isset($notify_action_target)) {
            return [];
        }

        if (!is_array($notify_action_target)) {
            $notify_action_target = [$notify_action_target];
        }

        // loop
        $users = collect([]);
        foreach ($notify_action_target as $notify_act) {
            $users_inner = NotifyTarget::getModels($this, $custom_value, $notify_act);
            foreach ($users_inner as $u) {
                $users->push($u);
            }
        }

        return $users;
    }

    /**
     * whether $user is target send user
     */
    protected function approvalSendUser($mail_template, $custom_table, $data, NotifyTarget $user, $checkHistory = true)
    {
        // if $user is myself, return false
        if ($checkHistory && \Exment::user()->email == $user->email()) {
            return false;
        }

        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);

        // if already send notify in 1 minutes, continue.
        if ($checkHistory) {
            $index_user = CustomColumn::getEloquent('user', $mail_send_log_table)->getIndexColumnName();
            $index_mail_template = CustomColumn::getEloquent('mail_template', $mail_send_log_table)->getIndexColumnName();
            $mail_send_histories = getModelName(SystemTableName::MAIL_SEND_LOG)
                ::where($index_user, $user->id())
                ->where($index_mail_template, $mail_template->id)
                ->where('parent_id', $data->id)
                ->where('parent_type', $custom_table->table_name)
                ->get()
            ;

            foreach ($mail_send_histories as $mail_send_log) {
                // If user were sending within 5 minutes, false
                $skip_mitutes = config('exment.notify_saved_skip_minutes', 5);
                $send_datetime = (new Carbon($mail_send_log->getValue('send_datetime')))
                    ->addMinutes($skip_mitutes);
                $now = Carbon::now();
                if ($send_datetime->gt($now)) {
                    return false;
                }
            }
        }

        return true;
    }
}
