<?php

namespace Exceedone\Exment\Model;

use Exceedone\Exment\ColumnItems;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\GroupCondition;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifySavedType;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Services\Notify\NotifyTargetBase;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\Search\SearchService;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;
use Carbon\Carbon;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Notify user.
 *
 * *Now disable these params.
 * - custom_table_id to target_id
 * - workflow_id to target_id
 * - notify_actions to action_settings
 *
 * @phpstan-consistent-constructor
 * @property mixed $custom_view
 * @property mixed $suuid
 * @property mixed $target_id
 * @property mixed $mail_template_id
 * @property mixed $notify_trigger
 * @property mixed $notify_view_name
 * @property mixed $custom_table
 * @property mixed $action_settings
 * @property mixed $notify_name
 * @property mixed $notify_action
 * @property mixed $custom_table_id
 * @property mixed $workflow_id
 * @method static \Illuminate\Database\Query\Builder whereIn($column, $values, $boolean = 'and', $not = false)
 * @method static \Illuminate\Database\Query\Builder whereNotIn($column, $values, $boolean = 'and')
 */
class Notify extends ModelBase
{
    use Traits\UseRequestSessionTrait;
    use Traits\AutoSUuidTrait;
    use Traits\DatabaseJsonTrait;
    use Traits\ColumnOptionQueryTrait;
    use Notifiable;

    protected $guarded = ['id'];
    protected $casts = ['trigger_settings' => 'json', 'action_settings' => 'json'];

    protected $_schedule_date_column_item;

    public function custom_table(): ?BelongsTo
    {
        if (!in_array($this->notify_trigger, NotifyTrigger::CUSTOM_TABLES())) {
            return null;
        }
        return $this->belongsTo(CustomTable::class, 'target_id')
        ;
    }

    public function custom_view(): BelongsTo
    {
        if (isset($this->custom_view_id)) {
            return $this->belongsTo(CustomView::class, 'custom_view_id');
        }
        return $this->belongsTo(CustomView::class, 'custom_view_id')->whereNotMatch();
    }

    public function getNotifyActionsAttribute()
    {
        return explode(",", array_get($this->attributes, 'notify_actions'));
    }

    public function getTriggerSettingsAttribute()
    {
        $trigger_settings = $this->getAttributeFromArray('trigger_settings');
        $trigger_settings = $this->castAttribute('trigger_settings', $trigger_settings);

        $notify_target_column = array_get($trigger_settings, 'notify_target_column');
        $notify_target_table_id = array_get($trigger_settings, 'notify_target_table_id');

        // if (!isset($notify_target_column) || !isset($notify_target_table_id)) {
        //     return $trigger_settings;
        // }

        $optionKeyParams = [];
        if (!is_nullorempty($v = array_get($trigger_settings, 'view_pivot_column_id'))) {
            $optionKeyParams['view_pivot_column'] = $v;
        }
        if (!is_nullorempty($v = array_get($trigger_settings, 'view_pivot_table_id'))) {
            $optionKeyParams['view_pivot_table'] = $v;
        }

        $trigger_settings['notify_target_date'] = static::getOptionKey($notify_target_column, true, $notify_target_table_id, $optionKeyParams);

        return $trigger_settings;
    }

    public function setTriggerSettingsAttribute($value = null)
    {
        $notify_target_date = array_get($value, 'notify_target_date');

        if (isset($notify_target_date)) {
            list($column_type, $column_table_id, $column_type_target, $view_pivot_column, $view_pivot_table) = $this->getViewColumnTargetItems($notify_target_date);

            $value['notify_target_column'] = $column_type_target;
            $value['notify_target_table_id'] = $column_table_id;
            if (!is_nullorempty($view_pivot_column)) {
                $value['view_pivot_column_id'] = $view_pivot_column;
            }
            if (!is_nullorempty($view_pivot_table)) {
                $value['view_pivot_table_id'] = $view_pivot_table;
            }
            unset($value['notify_target_date']);
        }

        $value = $this->castAttributeAsJson('trigger_settings', $value);

        $this->attributes['trigger_settings'] = $value;
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
     * Get schedule date's column item.
     *
     * @return mixed|null
     */
    public function getScheduleDateColumnItemAttribute()
    {
        if (isset($this->_schedule_date_column_item)) {
            return $this->_schedule_date_column_item;
        }

        // Now only column.
        $custom_column = CustomColumn::getEloquent(array_get($this, 'trigger_settings.notify_target_column'));
        $query_key = \Exment::getOptionKey($custom_column->id, true, $custom_column->custom_table_id, array_get($this, 'trigger_settings'));

        $this->_schedule_date_column_item = ColumnItems\CustomItem::getItem($custom_column, null, $query_key);

        if (!is_nullorempty($this->suuid)) {
            $this->_schedule_date_column_item->setUniqueName(Define::COLUMN_ITEM_UNIQUE_PREFIX . $this->suuid);
        }

        return $this->_schedule_date_column_item;
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
                'notify_target_column_value' => $this->getNotifyTargetValue($custom_value, $column),
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
                            'final_user' => $user->id() === $users->last()->id(),
                        ]);
                    }
                    // throw mailsend Exception
                    catch (TransportExceptionInterface $ex) {
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
        if (!$this->isNotifyTarget($custom_value, NotifyTrigger::CREATE_UPDATE_DATA, $notifySavedType)) {
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
            $users = $this->getNotifyTargetUsers($custom_value, $action_setting, $custom_table);
            if (isset($options['targetUserOrgs'])) {
                $targetUserOrgs = [];
                foreach ($options['targetUserOrgs'] as $targetUserOrg) {
                    if ($targetUserOrg->custom_table->table_name == SystemTableName::ORGANIZATION) {
                        $targetUserOrgs = array_merge($targetUserOrgs, $targetUserOrg->users->pluck('id')->toArray());
                    } else {
                        $targetUserOrgs[] = $targetUserOrg->id;
                    }
                }

                // get users
                $targetUserOrgs = getModelName(SystemTableName::USER)::find($targetUserOrgs);
                // convert as NotifyTarget
                $targetUserOrgs = $targetUserOrgs->map(function ($user) {
                    return NotifyTarget::getModelAsUser($user);
                });
                foreach ($users as $item) {
                    $targetUserOrgs->push($item);
                }
                $users = $targetUserOrgs;
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
                        'final_user' => $user->id() === $users->last()->id(),
                    ]);
                }
                // throw mailsend Exception
                catch (TransportExceptionInterface $ex) {
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

        if (!$this->isNotifyWorkflowTarget($workflow_action, $statusTo)) {
            return;
        }

        $custom_value->refresh();

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
                        'final_user' => $user->id() === $users->last()->id(),
                    ]);
                }
                // throw mailsend Exception
                catch (TransportExceptionInterface $ex) {
                    \Log::error($ex);
                    // show warning message
                    admin_warning(exmtrans('error.header'), exmtrans('error.mailsend_failed'));
                }
            }
        }
    }

    /**
     * check if notify workflow target data
     *
     * @param WorkflowAction $workflow_action
     * @param string $statusTo
     * @return boolean
     */
    public function isNotifyWorkflowTarget(WorkflowAction $workflow_action, $statusTo)
    {
        $filter_status_to = array_get($this->trigger_settings, 'filter_status_to');
        $filter_actions = array_get($this->trigger_settings, 'filter_actions');

        if (!is_nullorempty($filter_status_to)) {
            if (!is_array($filter_status_to)) {
                $filter_status_to = [$filter_status_to];
            }
            if (!in_array($statusTo, $filter_status_to)) {
                return false;
            }
        }

        if (!is_nullorempty($filter_actions)) {
            if (!is_array($filter_actions)) {
                $filter_actions = [$filter_actions];
            }
            if (!in_array($workflow_action->id, $filter_actions)) {
                return false;
            }
        }
        return true;
    }

    /**
     * check if notify target data
     *
     * @param CustomValue $custom_value
     * @param string $notify_trigger
     * @param string $notifySavedType
     * @return boolean
     */
    public function isNotifyTarget($custom_value, $notify_trigger, $notifySavedType = null)
    {
        if (array_get($this, 'notify_trigger') != $notify_trigger) {
            return false;
        }
        $custom_view_id = array_get($this, 'custom_view_id');
        if (isset($custom_view_id)) {
            $custom_view = CustomView::getEloquent($custom_view_id);
            if (isset($custom_view)) {
                $custom_table = $custom_value->custom_table;
                $query = $custom_table->getValueQuery();
                $table_name = getDBTableName($custom_table);
                if ($notifySavedType === NotifySavedType::DELETE) {
                    return $custom_view->setValueFilters($query)->where("$table_name.id", $custom_value->id)->withTrashed()->exists();
                } else {
                    return $custom_view->setValueFilters($query)->where("$table_name.id", $custom_value->id)->exists();
                }
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
                        'final_user' => $target_user_key === end($target_user_keys),
                    ]);
                }
                // throw mailsend Exception
                catch (TransportExceptionInterface $ex) {
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
        $target_date = Carbon::today()->addDays($before_after_number * $notify_day * -1);
        $target_date_str = $target_date->format('Y-m-d');
        $table = $this->custom_table;
        $column = CustomColumn::getEloquent(array_get($this, 'trigger_settings.notify_target_column'));

        // get search service
        $query = $table->getValueQuery();
        if (isset($this->custom_view_id)) {
            $this->custom_view->setValueFilters($query);
            $service = $this->custom_view->getSearchService();
        } else {
            $service = new SearchService($table);
        }
        $service->setQuery($query);

        $datalist = $service->whereNotifySchedule($this, '=', $target_date_str, 'and', ['format' => GroupCondition::YMD])->get();

        return [$datalist, $table, $column];
    }

    /**
     * get notify target users
     *
     * @param CustomValue $custom_value target custom value
     * @param array $action_setting
     * @param CustomTable|null $custom_table
     * @return array|Collection|\Tightenco\Collect\Support\Collection
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
     * @param CustomValue $custom_value
     * @param array $action_setting
     * @param WorkflowAction $workflow_action
     * @param WorkflowValue $workflow_value
     * @param $statusTo
     * @return array|Collection|\Tightenco\Collect\Support\Collection
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
            $mail_send_histories = getModelName(SystemTableName::MAIL_SEND_LOG)::where($index_user, $user->id())
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
    protected function isNotifyMyself(): bool
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
    protected function uniqueUsers($users): Collection
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

    protected function getNotifyTargetValue($custom_value, $column)
    {
        $item = $column->column_item
        ->options([
            'view_pivot_column' => $this->getTriggerSetting('view_pivot_column_id'),
            'view_pivot_table' => $this->getTriggerSetting('view_pivot_table_id'),
        ]);

        return $item->setCustomValue($custom_value)->value();
    }
}
