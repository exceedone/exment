<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Services\MailSender;
use Exceedone\Exment\Services\NotifyService;
use Carbon\Carbon;

class Notify extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;

    protected $guarded = ['id'];
    protected $appends = ['notify_actions'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
    
    public function custom_table()
    {
        return $this->belongsTo(CustomTable::class, 'custom_table_id');
    }
    
    public function custom_view()
    {
        if (isset($this->custom_view_id)) {
            return $this->belongsTo(CustomView::class, 'custom_view_id');
        }
        return null;
    }
    
    public function setNotifyActionsAttribute($notifyActions)
    {
        if(is_null($notifyActions)){
            $this->attributes['notify_actions'] = null;    
        }elseif(is_string($notifyActions)){
            $this->attributes['notify_actions'] = $notifyActions;
        }else{
            $this->attributes['notify_actions'] = implode(',', array_filter($notifyActions));
        }
    }
 
    public function getNotifyActionsAttribute()
    {
        return explode(",", array_get($this->attributes, 'notify_actions'));
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
        foreach ($datalist as $custom_value) {
            $users = $this->getNotifyTargetUsers($custom_value);
            foreach ($users as $user) {
                $prms = [
                    'user' => $user,
                    'notify' => $this,
                    'target_table' => $table->table_view_name ?? null,
                    'notify_target_column_key' => $column->column_view_name ?? null,
                    'notify_target_column_value' => $custom_value->getValue($column),
                ];

                // send mail
                try {
                    NotifyService::executeNotifyAction($this, [
                        'prms' => $prms,
                        'user' => $user,
                        'custom_value' => $custom_value,
                    ]);
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
     * *Contains Comment, share
     */
    public function notifyCreateUpdateUser($custom_value, $notifySavedType)
    {
        if (!$this->isNotifyTarget($custom_value, $notifySavedType)) {
            return;
        }

        // check trigger
        $notify_saved_triggers = array_get($this, 'trigger_settings.notify_saved_trigger', []);
        if(!isset($notify_saved_triggers) || !in_array($notifySavedType, $notify_saved_triggers)){
            return;
        }

        $custom_table = $custom_value->custom_table;
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);
        $mail_template = $this->getMailTemplate();

        // loop custom_value
        $users = $this->getNotifyTargetUsers($custom_value);
        
        foreach ($users as $user) {
            if (!$this->approvalSendUser($mail_template, $custom_table, $custom_value, $user)) {
                continue;
            }

            $prms = [
                'user' => $user,
                'notify' => $this,
                'target_table' => $custom_table->table_view_name ?? null,
                'create_or_update' => NotifySavedType::getEnum($notifySavedType)->getLabel(),
            ];

            // send mail
            try {
                NotifyService::executeNotifyAction($this, [
                    'mail_template' => $mail_template,
                    'prms' => $prms,
                    'user' => $user,
                    'custom_value' => $custom_value,
                ]);
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
     * *Contains Comment, share
     */
    public function notifyShared($custom_value, $targetUserOrgs)
    {
        // check trigger
        $notify_saved_triggers = array_get($this, 'trigger_settings.notify_saved_trigger', []);
        if(!isset($notify_saved_triggers) || !in_array(NotifySavedType::SHARE, $notify_saved_triggers)){
            return;
        }

        $custom_table = $custom_value->custom_table;
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);
        $mail_template = $this->getMailTemplate();

        // loop data
        $users = [];
        foreach($targetUserOrgs as $targetUserOrg){
            if($targetUserOrg->custom_table->table_name == SystemTableName::ORGANIZATION){
                $users = array_merge($users, $targetUserOrg->users);
            }else{
                $users[] = $targetUserOrg;
            }
        }
        
        foreach ($users as $user) {
            $prms = [
                'user' => $user,
                'notify' => $this,
                'target_table' => $custom_table->table_view_name ?? null,
                'create_or_update' => NotifySavedType::getEnum($notifySavedType)->getLabel(),
            ];

            // send mail
            try {
                NotifyService::executeNotifyAction($this, [
                    'mail_template' => $mail_template,
                    'prms' => $prms,
                    'user' => $user,
                    'custom_value' => $custom_value,
                ]);
            }
            // throw mailsend Exception
            catch (\Swift_TransportException $ex) {
                // show warning message
                admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
            }
        }
    }

    /**
     * check if notify target data
     *
     * @param CustomValue $custom_value
     * @param [type] $notify_trigger
     * @return boolean
     */
    public function isNotifyTarget($custom_value, $notify_trigger)
    {
        if (array_get($this, 'notify_trigger') != $notify_trigger) {
            return false;
        }
        $custom_view_id = array_get($this, 'custom_view_id');
        if (isset($custom_view_id)) {
            $custom_view = CustomView::getEloquent($custom_view_id);
            return $custom_view->setValueFilters($custom_value->custom_table->getValueModel())
                ->where('id', $custom_value->id)->exists();
        }
        return true;
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
                NotifyService::executeNotifyAction($this, [
                    'mail_template' => $mail_template,
                    'prms' => $prms,
                    'user' => $user,
                    'custom_value' => $custom_value,
                    'subject' => $subject,
                    'body' => $body,
                    'attachments' => $attachments,
                ]);
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

        //ymd row
        $raw = \DB::getQueryGrammar()->getDateFormatString(GroupCondition::YMD, 'value->'.$column->column_name, false, false);

        // find data. where equal target_date
        $datalist = $this->custom_view->setValueFilters($table->getValueModel())
            ->whereRaw("$raw = ?", [$target_date_str])->get();

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
    protected function approvalSendUser($mail_template, $custom_table, $custom_value, NotifyTarget $user, $checkHistory = true)
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
                ->where('parent_id', $custom_value->id)
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
