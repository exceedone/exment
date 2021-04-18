<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Services\Notify\NotifyTargetBase;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Carbon\Carbon;

/**
 * Notify user.
 *
 * *Now disable these params.
 * - custom_table_id to target_id
 * - workflow_id to target_id
 * - notify_actions to action_settings
 */
class Notify extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Notifiable;

    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];
    
    public function custom_table()
    {
        if (!in_array($this->notify_trigger, NotifyTrigger::CUSTOM_TABLES())) {
            return null;
        }
        return $this->belongsTo(CustomTable::class, 'target_id')
            ;
    }
    
    public function custom_view()
    {
        if (isset($this->custom_view_id)) {
            return $this->belongsTo(CustomView::class, 'custom_view_id');
        }
        return null;
    }
    
    public function getNotifyActionsAttribute()
    {
        return explode(",", array_get($this->attributes, 'notify_actions'));
    }

    public function getMailTemplate()
    {
        $mail_template_id = array_get($this, 'mail_template_id');

        if (isset($mail_template_id)) {
            return getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_template_id);
        }
    }

    public function getTriggerSetting($key, $default = null)
    {
        return $this->getJson('trigger_settings', $key, $default);
    }
    public function setTriggerSetting($key, $value = null)
    {
        return $this->setJson('trigger_settings', $key, $value);
    }

    public function getMentionHere($action_setting)
    {
        $mention_here = array_get($action_setting, 'mention_here', false);

        return boolval($mention_here);
    }

    public function getMentionUsers($users)
    {
        return collect($users)->map(function ($user) {
            return $user->slack_id();
        })->filter()->unique()->toArray();
    }

    /**
     * notify user on schedule
     */
    public function notifySchedule()
    {
        list($datalist, $table, $column) = $this->getNotifyTargetDatalist();

        // loop data
        foreach ($datalist as $custom_value) {
            $prms = [
                'notify' => $this,
                'target_table' => $table->table_view_name ?? null,
                'notify_target_column_key' => $column->column_view_name ?? null,
                'notify_target_column_value' => $custom_value->getValue($column),
            ];
    
            foreach ($this->action_settings as $action_setting) {
                $users = $this->getNotifyTargetUsers($custom_value, $action_setting);

                if (NotifyAction::isChatMessage($action_setting)) {
                    // send slack message
                    NotifyService::executeNotifyAction($this, [
                        'prms' => $prms,
                        'custom_value' => $custom_value,
                        'mention_here' => $this->getMentionHere($action_setting),
                        'mention_users' => $this->getMentionUsers($users),
                        'is_chat' => true,
                        'action_setting' => $action_setting,
                    ]);
                    continue;
                }
    
                // return function if no user-targeted action is included
                if (!NotifyAction::isUserTarget($action_setting)) {
                    continue;
                }
        
                $users = $this->uniqueUsers($users);
                foreach ($users as $user) {
                    // send mail
                    try {
                        NotifyService::executeNotifyAction($this, [
                            'prms' => array_merge(['user' => $user->toArray()], $prms),
                            'user' => $user,
                            'custom_value' => $custom_value,
                            'action_setting' => $action_setting,
                        ]);
                    }
                    // throw mailsend Exception
                    catch (\Swift_TransportException $ex) {
                        \Log::error($ex);
                    }
                }
            }
        }
    }
    
    
    /**
     * notify_create_update_user
     * *Contains Comment, share
     */
    public function notifyCreateUpdateUser($custom_value, $notifySavedType, $options = [])
    {
        if (!$this->isNotifyTarget($custom_value, NotifyTrigger::CREATE_UPDATE_DATA)) {
            return;
        }

        $notifySavedType = NotifySavedType::getEnum($notifySavedType);

        // check trigger
        $notify_saved_triggers = array_get($this, 'trigger_settings.notify_saved_trigger', []);
        if (!isset($notify_saved_triggers) || !in_array($notifySavedType, $notify_saved_triggers)) {
            return;
        }

        $prms = [
            'target_user' => $notifySavedType->getTargetUserName($custom_value),
            'create_or_update' => $notifySavedType->getLabel(),
        ];
        $options['prms'] = $prms;

        return $this->notifyUser($custom_value, $options);
    }
    
    /**
     * notify target user.
     * *Contains Comment, share
     */
    public function notifyUser($custom_value, $options = [])
    {
        $options = array_merge(
            [
                'targetUserOrgs' => null,
                'comment' => null,
                'attachment' => null,
                'prms' => [],
                'custom_table' => null, // Set custom table if custom value is null.
            ],
            $options
        );
        $prms = $options['prms'];

        $custom_table = $options['custom_table'] ?? $custom_value->custom_table;
        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);
        $mail_template = $this->getMailTemplate();

        // create freespace
        $freeSpace = '';
        if (isset($options['comment'])) {
            $freeSpace = "\n" . exmtrans('common.comment') . ":\n" . $options['comment'] . "\n";
        } elseif (isset($options['attachment'])) {
            $freeSpace = exmtrans('common.attachment') . ":" . $options['attachment'];
        }

        $prms = array_merge([
            'notify' => $this,
            'target_table' => $custom_table->table_view_name ?? null,
            'target_datetime' => \Carbon\Carbon::now()->format('Y-m-d H:i:s'),
            'free_space' => $freeSpace,
        ], $prms);

        // loop action setting
        foreach ($this->action_settings as $action_setting) {
            if (!isset($options['targetUserOrgs'])) {
                $users = $this->getNotifyTargetUsers($custom_value, $action_setting, $custom_table);
            } else {
                $users = [];
                foreach ($options['targetUserOrgs'] as $targetUserOrg) {
                    if ($targetUserOrg->custom_table->table_name == SystemTableName::ORGANIZATION) {
                        $users = array_merge($users, $targetUserOrg->users->pluck('id')->toArray());
                    } else {
                        $users[] = $targetUserOrg->id;
                    }
                }

                // get users
                $users = getModelName(SystemTableName::USER)::find($users);
                // convert as NotifyTarget
                $users = $users->map(function ($user) {
                    return NotifyTarget::getModelAsUser($user);
                });
            }
            
            if (NotifyAction::isChatMessage($action_setting)) {
                // send slack message
                NotifyService::executeNotifyAction($this, [
                    'mail_template' => $mail_template,
                    'prms' => $prms,
                    'custom_table' => $custom_table,
                    'custom_value' => $custom_value,
                    'mention_here' => $this->getMentionHere($action_setting),
                    'mention_users' => $this->getMentionUsers($users),
                    'is_chat' => true,
                    'action_setting' => $action_setting,
                ]);
                continue;
            }

            // continue function if no user-targeted action is included
            if (!NotifyAction::isUserTarget($action_setting)) {
                continue;
            }

            $users = $this->uniqueUsers($users);
            foreach ($users as $user) {
                if (!$this->approvalSendUser($mail_template, $custom_table, $custom_value, $user)) {
                    continue;
                }

                // send notify
                try {
                    NotifyService::executeNotifyAction($this, [
                        'mail_template' => $mail_template,
                        'prms' => array_merge(['user' => $user->toArray()], $prms),
                        'user' => $user,
                        'custom_table' => $custom_table,
                        'custom_value' => $custom_value,
                        'action_setting' => $action_setting,
                    ]);
                }
                // throw mailsend Exception
                catch (\Swift_TransportException $ex) {
                    \Log::error($ex);
                    // show warning message
                    admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
                }
            }
        }
    }
    

    /**
     * notify workflow
     * *Contains Comment, share
     */
    public function notifyWorkflow(CustomValue $custom_value, WorkflowAction $workflow_action, WorkflowValue $workflow_value, $statusTo)
    {
        $workflow = $workflow_action->workflow_cache;

        // loop action setting
        foreach ($this->action_settings as $action_setting) {
            $users = $this->getNotifyTargetUsersWorkflow($custom_value, $action_setting, $workflow_action, $workflow_value, $statusTo);
            $mail_template = $this->getMailTemplate();
    
            $prms = [
                'notify' => $this,
            ];
            if (NotifyAction::isChatMessage($action_setting)) {
                // send slack message
                NotifyService::executeNotifyAction($this, [
                    'mail_template' => $mail_template,
                    'prms' => $prms,
                    'custom_value' => $custom_value,
                    'mention_here' => $this->getMentionHere($action_setting),
                    'mention_users' => $this->getMentionUsers($users),
                    'is_chat' => true,
                    'replaceOptions' => [
                        'workflow_action' => $workflow_action,
                        'workflow_value' => $workflow_value,
                    ],
                    'action_setting' => $action_setting,
                ]);
                continue;
            }
    
            // return function if no user-targeted action is included
            if (!NotifyAction::isUserTarget($action_setting)) {
                continue;
            }
    
            $users = $this->uniqueUsers($users);
            foreach ($users as $user) {
                // send mail
                try {
                    NotifyService::executeNotifyAction($this, [
                        'mail_template' => $mail_template,
                        'prms' => array_merge(['user' => $user->toArray()], $prms),
                        'user' => $user,
                        'custom_value' => $custom_value,
                        'replaceOptions' => [
                            'workflow_action' => $workflow_action,
                            'workflow_value' => $workflow_value,
                        ],
                        'action_setting' => $action_setting,
                    ]);
                }
                // throw mailsend Exception
                catch (\Swift_TransportException $ex) {
                    \Log::error($ex);
                    // show warning message
                    admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
                }
            }
        }
    }
    
    /**
     * check if notify target data
     *
     * @param CustomValue $custom_value
     * @param string $notify_trigger
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
            if (isset($custom_view)) {
                $query = $custom_value->custom_table->getValueQuery();
                return $custom_view->setValueFilters($query)->where('id', $custom_value->id)->exists();
            }
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
        $attach_files = collect($attachments)->filter()->map(function ($uuid) {
            return File::where('uuid', $uuid)->first();
        })->filter();

        // loop action setting
        foreach ($this->action_settings as $action_setting) {
            if (NotifyAction::isChatMessage($action_setting)) {
                $users = NotifyTarget::getSelectedNotifyTargets($target_user_keys, $this, $custom_value);

                // send slack message
                NotifyService::executeNotifyAction($this, [
                    'mail_template' => $mail_template,
                    'prms' => [
                        'notify' => $this,
                        'target_table' => $custom_table->table_view_name ?? null
                    ],
                    'custom_value' => $custom_value,
                    'subject' => $subject,
                    'body' => $body,
                    'mention_here' => $this->getMentionHere($action_setting),
                    'mention_users' => $this->getMentionUsers($users),
                    'is_chat' => true,
                    'action_setting' => $action_setting,
                ]);
                continue;
            }

            // continue function if no user-targeted action is included
            if (!NotifyAction::isUserTarget($action_setting)) {
                continue;
            }

            // loop target users
            foreach ($target_user_keys as $target_user_key) {
                $user = NotifyTarget::getSelectedNotifyTarget($target_user_key, $this, $custom_value);
                if (!isset($user)) {
                    continue;
                }

                // if (!$this->approvalSendUser($mail_template, $custom_table, $custom_value, $user, false)) {
                //     continue;
                // }

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
                        'attach_files' => $attach_files,
                        'action_setting' => $action_setting,
                    ]);
                }
                // throw mailsend Exception
                catch (\Swift_TransportException $ex) {
                    \Log::error($ex);
                    // show warning message
                    admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
                }
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
        if (isset($this->custom_view_id)) {
            $datalist = $this->custom_view->setValueFilters($table->getValueQuery())
                ->whereRaw("$raw = ?", [$target_date_str])->get();
        } else {
            $datalist = getModelName($table)::whereRaw("$raw = ?", [$target_date_str])->get();
        }

        return [$datalist, $table, $column];
    }
       
    /**
     * get notify target users
     *
     * @param CustomValue $custom_value target custom value
     * @param array $action_setting
     * @return array
     */
    public function getNotifyTargetUsers($custom_value, array $action_setting, ?CustomTable $custom_table = null)
    {
        $notify_action_target = array_get($action_setting, 'notify_action_target');
        if (!isset($notify_action_target)) {
            return [];
        }

        // loop
        $values = collect([]);
        foreach (stringToArray($notify_action_target) as $notify_act) {
            $values_inner = NotifyTarget::getModels($this, $custom_value, $notify_act, $action_setting, $custom_table);
            foreach ($values_inner as $u) {
                $values->push($u);
            }
        }

        return $values;
    }


    /**
     * get notify target users for workflow
     *
     * @param CustomValue $custom_value target custom value
     * @param array $action_setting
     * @return array
     */
    public function getNotifyTargetUsersWorkflow(CustomValue $custom_value, array $action_setting, WorkflowAction $workflow_action, WorkflowValue $workflow_value, $statusTo)
    {
        $notify_action_target = array_get($action_setting, 'notify_action_target');
        if (!isset($notify_action_target)) {
            return [];
        }

        // loop
        $values = collect();
        foreach (stringToArray($notify_action_target) as $notify_act) {
            $notifyTarget = NotifyTargetBase::make($notify_act, $this, $action_setting);
            if (!$notifyTarget) {
                continue;
            }

            $values_inner = $notifyTarget->getModelsWorkflow($custom_value, $workflow_action, $workflow_value, $statusTo);
            foreach ($values_inner as $u) {
                $values->push($u);
            }
        }
        
        $loginuser = \Exment::user();
        $values = $values->unique()->filter(function ($value) use ($loginuser) {
            if (is_nullorempty($loginuser)) {
                return true;
            }
            if ($this->isNotifyMyself()) {
                return true;
            }
            if ($loginuser->getUserId() != $value->getUserId()) {
                return true;
            }
            return false;
        });

        return $values;
    }


    /**
     * whether $user is target send user
     */
    protected function approvalSendUser($mail_template, $custom_table, $custom_value, NotifyTarget $user, $checkHistory = true)
    {
        // if $user is myself, return false
        $loginuser = \Exment::user();
        if (!$this->isNotifyMyself()) {
            if ($checkHistory && !is_nullorempty($loginuser) && isMatchString($loginuser->email, $user->email())) {
                return false;
            }
        }

        $mail_send_log_table = CustomTable::getEloquent(SystemTableName::MAIL_SEND_LOG);

        // if already send notify in 1 minutes, continue.
        if ($checkHistory && $custom_value) {
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

    /**
     * Whether skip notify myself
     *
     * @return bool
     */
    protected function isNotifyMyself() : bool
    {
        // only CREATE_UPDATE_DATA and WORKFLOW
        if (!in_array($this->notify_trigger, [NotifyTrigger::CREATE_UPDATE_DATA, NotifyTrigger::WORKFLOW])) {
            return true;
        }
        return boolval($this->getTriggerSetting('notify_myself') ?? false);
    }
    
    /**
     * Unique users. unique key is mail address.
     *
     * @param array|Collection $users
     * @return Collection
     */
    protected function uniqueUsers($users) : Collection
    {
        return collect($users)->unique(function ($user) {
            return NotifyService::getAddress($user);
        })->filter();
    }

    protected static function boot()
    {
        parent::boot();

        static::saving(function ($model) {
            // set custom_table_id because cannot null.
            if (is_null(array_get($model->attributes, 'custom_table_id'))) {
                $model->attributes['custom_table_id'] = 0;
            }
        });
    }
}
