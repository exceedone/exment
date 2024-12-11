<?php

namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Model\RelationTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\SearchType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Notifications;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Notify dialog, send mail etc.
 */
class NotifyService
{
    protected $notify;

    protected $targetid;

    protected $custom_table;

    protected $custom_value;

    public function __construct(Notify $notify, $targetid, $tableKey, $id)
    {
        $this->notify = $notify;
        $this->targetid = $targetid;

        $this->custom_table = CustomTable::getEloquent($tableKey);
        $this->custom_value = isset($this->custom_table) ? $this->custom_table->getValueModel($id) : null;
    }

    /**
     * Get dialog form for send mail
     *
     * @return ModalForm|false
     */
    public function getNotifyDialogForm()
    {
        // get target users
        $values = collect();
        foreach ($this->notify->action_settings as $action_setting) {
            $values = $values->merge($this->notify->getNotifyTargetUsers($this->custom_value, $action_setting));
        }

        if (!$this->hasNotifyUserByButton($values)) {
            return false;
        }

        // if only one data, get form for detail
        if (count($values) <= 1) {
            return $this->getSendForm($values);
        }

        // create form fields
        $tableKey = $this->custom_table->table_name;
        $id = $this->custom_value->id;

        $form = new ModalForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_notify_modal');
        $form->modalHeader(exmtrans('custom_value.sendmail.title'));
        $form->action(admin_urls('data', $tableKey, $id, 'sendTargetUsers'));

        // progress tracker
        $form->progressTracker()->options($this->getProgressInfo(true));

        $options = [];
        foreach ($values as $value) {
            $options[$value->notifyKey()] = $value->getLabel();
        }

        // select target users
        $form->listbox('target_users', exmtrans('custom_value.sendmail.mail_to'))
            ->options($options)
            ->required()
            ->help(exmtrans('common.bootstrap_duallistbox_container.help'))
            ->settings(['nonSelectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.nonSelectedListLabel'), 'selectedListLabel' => exmtrans('common.bootstrap_duallistbox_container.selectedListLabel')])
            ->setWidth(9, 2);

        $form->hidden('mail_template_id')->default($this->targetid);

        return $form;
    }

    /**
     *
     *
     * @param array|\Illuminate\Support\Collection $target_users
     * @return ModalForm
     */
    public function getNotifyDialogFormMultiple($target_users)
    {
        $users = [];
        foreach ($target_users as $target_user) {
            // get definition target users
            if (!is_null($user = NotifyTarget::getSelectedNotifyTarget($target_user, $this->notify, $this->custom_value))) {
                $users[] = $user;
            }
        }

        return $this->getSendForm($users, true);
    }

    /**
     * Get Send Form. if only one user, Replace format.
     *
     * @param $notifyTargets
     * @param $isFlow
     * @return ModalForm|false
     */
    protected function getSendForm($notifyTargets, $isFlow = false)
    {
        $tableKey = $this->custom_table->table_name;
        $id = $this->custom_value->id;

        $mail_template = $this->notify->getMailTemplate();
        if (!isset($mail_template)) {
            abort(404);
        }

        $notifyTargets = collect($notifyTargets);
        if (!$this->hasNotifyUserByButton($notifyTargets)) {
            return false;
        }

        $replace = $notifyTargets->count() == 1;
        $mail_subject = array_get($mail_template->value, 'mail_subject');
        $mail_body = $mail_template->getJoinedBody();

        $notifyTarget = $this->getNotifyTargetLabel($notifyTargets);

        $notifyTargetJson = json_encode($notifyTargets->map(function ($notifyTarget) {
            return $notifyTarget->notifyKey();
        })->toArray());

        if ($replace) {
            $mail_subject = replaceTextFromFormat($mail_subject, $this->custom_value);
            $mail_body = replaceTextFromFormat($mail_body, $this->custom_value);
        }

        // create form fields
        $form = new ModalForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_notify_modal');
        $form->modalHeader(exmtrans('custom_value.sendmail.title'));
        $form->action(admin_urls('data', $tableKey, $this->custom_value->id, 'sendMail'));

        if ($isFlow) {
            // progress tracker
            $form->progressTracker()->options($this->getProgressInfo(false));
        }

        $form->display(exmtrans('custom_value.sendmail.mail_to'))->default($notifyTarget);
        $form->hidden('target_users')->default($notifyTargetJson);

        $form->text('mail_title', exmtrans('custom_value.sendmail.mail_title'))
            ->default($mail_subject)
            ->required();

        $form->textarea('mail_message', exmtrans('custom_value.sendmail.mail_message'))
            ->default($mail_body)
            ->required()
            ->rows(10);

        $options = ExmentFile::where('parent_type', $tableKey)
            ->where('parent_id', $id)->get()->pluck('filename', 'uuid');

        if (collect($this->notify->action_settings)->contains(function ($notify_action) {
            return isMatchString(array_get($notify_action, 'notify_action'), NotifyAction::EMAIL);
        })) {
            $form->multipleSelect('mail_attachment', exmtrans('custom_value.sendmail.attachment'))
                ->options($options);
        }

        $form->textarea('send_error_message', exmtrans('custom_value.sendmail.send_error_message'))
            ->attribute(['readonly' => true, 'placeholder' => ''])
            ->rows(1)
            ->addElementClass('send_error_message');

        $form->hidden('mail_key_name')->default(array_get($mail_template->value, 'mail_key_name'));
        $form->hidden('mail_template_id')->default($this->targetid);

        $form->setWidth(8, 3);

        return $form;
    }

    /**
     * send notify mail
     *
     * @param $custom_table
     * @return \Symfony\Component\HttpFoundation\Response
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Psr\Container\NotFoundExceptionInterface
     */
    public function sendNotifyMail($custom_table)
    {
        $request = request();

        $title = $request->get('mail_title');
        $message = $request->get('mail_message');
        $attachments = $request->get('mail_attachment');
        $mail_key_name = $request->get('mail_key_name');
        $mail_template_id = $request->get('mail_template_id');

        // get target users
        $target_user_keys = json_decode_ex($request->get('target_users'), true);

        if (!isset($mail_key_name) || !isset($mail_template_id)) {
            abort(404);
        }

        $errors = [];

        if (isset($title) && isset($message)) {
            try {
                $this->notify->notifyButtonClick($this->custom_value, $target_user_keys, $title, $message, $attachments);
            } catch (TransportExceptionInterface $ex) {
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['send_error_message' => ['type' => 'input',
                        'message' => exmtrans('error.mailsend_failed')
                    ]],
                ]);
            } catch (\Exception $ex) {
                \Log::error($ex);
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['send_error_message' => ['type' => 'input',
                        'message' => exmtrans('error.mailsend_failed')
                    ]],
                ]);
            }
            return getAjaxResponse([
                'result'  => true,
                'toastr' => exmtrans('custom_value.sendmail.message.send_succeeded'),
            ]);
        } else {
            return getAjaxResponse([
                'result'  => false,
                'errors' => ['send_error_message' => ['type' => 'input',
                    'message' => exmtrans('custom_value.sendmail.message.empty_error')]],
            ]);
        }
    }


    /**
     * has notify User By Button action
     *
     * @param \Illuminate\Support\Collection $values
     * @return boolean
     */
    protected function hasNotifyUserByButton(\Illuminate\Support\Collection $values): bool
    {
        // Exists user, return true
        if ($values->count() > 0) {
            return true;
        }

        // contains webhook, return true.
        if (collect($this->notify->action_settings)->contains(function ($action_setting) {
            return NotifyAction::isChatMessage($action_setting);
        })) {
            return true;
        }

        return false;
    }


    /**
     * get notify target label
     *
     * @param \Illuminate\Support\Collection $notifyTargets
     * @return string
     */
    protected function getNotifyTargetLabel(\Illuminate\Support\Collection $notifyTargets): string
    {
        $targets = clone $notifyTargets;
        $targets = $targets->map(function ($notifyTarget) {
            return $notifyTarget->getLabel();
        });

        // contains webhook, Append label.
        if (collect($this->notify->action_settings)->contains(function ($action_setting) {
            return isMatchString(NotifyAction::SLACK, array_get($action_setting, 'notify_action'));
        })) {
            $targets->push(exmtrans('notify.notify_action_options.slack'));
        }
        if (collect($this->notify->action_settings)->contains(function ($action_setting) {
            return isMatchString(NotifyAction::MICROSOFT_TEAMS, array_get($action_setting, 'notify_action'));
        })) {
            $targets->push(exmtrans('notify.notify_action_options.microsoft_teams'));
        }

        return $targets->implode(exmtrans("common.separate_word"));
    }



    /**
     * Execute Notify test
     *
     * @param array $params
     * @return Notifications\SenderBase
     */
    public static function executeTestNotify($params = []): Notifications\SenderBase
    {
        $params = array_merge(
            [
                'to' => null,
                'type' => 'mail',
                'subject' => 'Exment TestMail',
                'body' => 'Exment TestMail'
            ],
            $params
        );
        $to = $params['to'];
        $type = $params['type'];
        $subject = $params['subject'];
        $body = $params['body'];


        // send mail
        try {
            $sender = Notifications\MailSender::make(null, $to);
            $sender->subject($subject)
                ->body($body)
                ->send();

            return $sender;
        }
        // throw mailsend Exception
        catch (TransportExceptionInterface $ex) {
            throw $ex;
        }
    }

    /**
     * Execute Notify action
     *
     * @param Notify $notify
     * @param array $params
     * @return void
     */
    public static function executeNotifyAction($notify, $params = [])
    {
        $params = array_merge(
            [
                'mail_template' => null,
                'custom_value' => null,
                'custom_table' => null,
                'subject' => null,
                'body' => null,
                'is_chat' => false,
                'mention_here' => false,
                'mention_users' => [],
                'action_setting' => null,
            ],
            $params
        );
        $params['notify'] = $notify;
        $custom_value = $params['custom_value'];
        $custom_table = isset($params['custom_table']) ? $params['custom_table'] : $custom_value->custom_table;

        Plugin::pluginExecuteEvent(PluginEventTrigger::NOTIFY_EXECUTING, $custom_table, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
            'notify' => $notify,
        ]);

        // get loop data for action_setting
        if (!isset($params['action_setting'])) {
            $action_settings = array_get($notify, 'action_settings', []);
        } else {
            $action_settings = [$params['action_setting']];
        }

        // get notify actions
        foreach ($action_settings as $action_setting) {
            $notify_action = array_get($action_setting, 'notify_action');
            if (is_nullorempty($notify_action)) {
                continue;
            }

            if (NotifyAction::isChatMessage($notify_action) != $params['is_chat']) {
                continue;
            }

            switch ($notify_action) {
                case NotifyAction::EMAIL:
                    static::notifyMail($params);
                    break;

                case NotifyAction::SHOW_PAGE:
                    static::notifyNavbar($params);
                    break;

                case NotifyAction::SLACK:
                    $params['webhook_url'] = array_get($action_setting, 'webhook_url');
                    static::notifySlack($params);
                    break;

                case NotifyAction::MICROSOFT_TEAMS:
                    $params['webhook_url'] = array_get($action_setting, 'webhook_url');
                    static::notifyTeams($params);
                    break;
            }
            // call notified trigger operations
            //CustomOperation::operationExecuteEvent(CustomOperationType::NOTIFIED, $custom_value, true);
        }

        Plugin::pluginExecuteEvent(PluginEventTrigger::NOTIFY_EXECUTED, $custom_table, [
            'custom_table' => $custom_table,
            'custom_value' => $custom_value,
            'notify' => $notify,
        ]);
    }

    /**
     * Notify email
     *
     * @param array $params
     * @return Notifications\SenderBase
     */
    public static function notifyMail(array $params = [])
    {
        $params = array_merge(
            [
                'mail_template' => null,
                'prms' => [],
                'user' => null,
                'custom_value' => null,
                'subject' => null,
                'body' => null,
                'to' => null,
                'cc' => [],
                'bcc' => [],
                'attach_files' => null,
                'disableHistoryBody' => false,
                'replaceOptions' => [],
                'final_user' => false,
            ],
            $params
        );
        static::replaceSubjectBody($params);

        // send mail
        try {
            $sender = Notifications\MailSender::make($params['mail_template'], $params['user'] ?? $params['to']);
            if (boolval($params['disableHistoryBody'])) {
                $sender->disableHistoryBody();
            }

            $sender->prms($params['prms'])
                ->user($params['user'])
                ->to($params['to'])
                ->custom_value($params['custom_value'])
                ->subject($params['subject'])
                ->body($params['body'])
                ->cc($params['cc'])
                ->bcc($params['bcc'])
                ->attachments($params['attach_files'])
                ->replaceOptions($params['replaceOptions'])
                ->finalUser($params['final_user'])
                ->send();

            return $sender;
        }
        // throw mailsend Exception
        catch (TransportExceptionInterface $ex) {
            throw $ex;
        }
    }


    /**
     * Notify navbar
     *
     * @param array $params
     * @return Notifications\SenderBase
     */
    public static function notifyNavbar(array $params = []): Notifications\SenderBase
    {
        $params = array_merge(
            [
                'notify' => null,
                'mail_template' => null,
                'prms' => [],
                'user' => null,
                'custom_table' => null,
                'custom_value' => null,
                'subject' => null,
                'body' => null,
                'replaceOptions' => [],
            ],
            $params
        );
        static::replaceSubjectBody($params);

        $notify = $params['notify'];
        $mail_template = $params['mail_template'];
        $prms = $params['prms'];
        $user = $params['user'];
        $custom_value = $params['custom_value'];
        $custom_table = isset($params['custom_table']) ? $params['custom_table'] : ($custom_value ? $custom_value->custom_table : null);
        $subject = $params['subject'];
        $body = $params['body'];
        $replaceOptions = $params['replaceOptions'];


        // replace system:site_name to custom_value label
        if (isset($custom_value)) {
            array_set($prms, 'system.site_name', $custom_value->label);
        }

        // replace value
        $mail_subject = static::replaceWord($subject, $custom_value, $prms, $replaceOptions);
        $mail_body = static::replaceWord($body, $custom_value, $prms, $replaceOptions);

        $sender = Notifications\NavbarSender::make(array_get($notify, 'id', -1), $mail_subject, $mail_body, $params);
        $sender->custom_value($custom_value)
            ->custom_table($custom_table)
            ->user($user)
            ->send();

        return $sender;
    }



    /**
     * Notify slack
     *
     * @param array $params
     * @return Notifications\SenderBase
     */
    public static function notifySlack(array $params = [])
    {
        return static::notifyWebHook($params, Notifications\SlackSender::class);
    }


    /**
     * Notify teams
     *
     * @param array $params
     * @return Notifications\SenderBase
     */
    public static function notifyTeams(array $params = [])
    {
        return static::notifyWebHook($params, Notifications\MicrosoftTeamsSender::class);
    }


    /**
     * Notify webhool
     *
     * @param array $params
     * @param string $className
     * @return Notifications\SenderBase
     */
    protected static function notifyWebHook(array $params, string $className): Notifications\SenderBase
    {
        $params = array_merge(
            [
                'webhook_url' => null,
                'webhook_name' => null,
                'webhook_icon' => null,
                'notify' => null,
                'mail_template' => null,
                'prms' => [],
                'custom_value' => null,
                'subject' => null,
                'body' => null,
                'replaceOptions' => [],
                'mention_here' => false,
                'mention_users' => [],
            ],
            $params
        );
        static::replaceSubjectBody($params);

        $webhook_url = $params['webhook_url'];

        // replace word
        $slack_subject = static::replaceWord($params['subject'], $params['custom_value'], $params['prms'], $params['replaceOptions']);
        $slack_body = static::replaceWord($params['body'], $params['custom_value'], $params['prms'], $params['replaceOptions']);

        // send message
        $options = ['webhook_name' => $params['webhook_name'], 'webhook_icon' => $params['webhook_icon'], 'mention_here' => $params['mention_here'], 'mention_users' => $params['mention_users']];
        $sender = $className::make($webhook_url, $slack_subject, $slack_body, $options);
        $sender->send();

        return $sender;
    }



    /**
     * replace subject and body from mail template
     */
    public static function replaceSubjectBody(&$params = [])
    {
        $params = array_merge(
            [
                'mail_template' => null,
                'notify' => null,
                'custom_value' => null,
            ],
            $params
        );
        $notify = $params['notify'];
        $mail_template = $params['mail_template'];
        $custom_value = $params['custom_value'];

        // get template
        if (!isset($mail_template) && isset($notify)) {
            $mail_template = array_get($notify, 'mail_template_id');
        }

        if (is_numeric($mail_template)) {
            $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::find($mail_template);
        } elseif (is_string($mail_template)) {
            $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', $mail_template)->first();
        }

        if (!isset($mail_template)) {
            return;
        }

        if (is_nullorempty($params['subject'])) {
            $params['subject'] = array_get($mail_template->value, 'mail_subject');
        }
        if (is_nullorempty($params['body'])) {
            $params['body'] = array_get($mail_template->value, 'mail_body');
        }
        if (array_key_exists('attach_files', $params)) {
            $attachments = array_get($mail_template->value, 'attachments');
            $params['attach_files'] = collect($attachments)->filter()->map(function ($attachment) {
                return ExmentFile::getData($attachment);
            })
            ->merge($params['attach_files'])
            ->merge($mail_template->getCustomAttachments($custom_value))->filter();
        }
    }

    /**
     * get Progress Info
     *
     * @param bool $isSelectTarget
     * @return array
     */
    protected function getProgressInfo($isSelectTarget)
    {
        $steps[] = [
            'active' => $isSelectTarget,
            'complete' => false,
            'url' => null,
            'description' => exmtrans('notify.notify_select')
        ];
        $steps[] = [
            'active' => !$isSelectTarget,
            'complete' => false,
            'url' => null,
            'description' => exmtrans('notify.message_input')
        ];
        return $steps;
    }



    /**
     * replace subject or body words.
     */
    public static function replaceWord($target, $custom_value = null, $prms = null, $replaceOptions = [])
    {
        $replaceOptions = array_merge([
            'matchBeforeCallback' => function ($length_array, $matchKey, $format, $custom_value, $options) use ($prms) {
                // if has prms using $match, return value
                if (isset($prms) && array_has($prms, $matchKey)) {
                    return array_get($prms, $matchKey);
                }
                $matchKey = str_replace(":", ".", $matchKey);
                if (isset($prms) && array_has($prms, $matchKey)) {
                    return array_get($prms, $matchKey);
                }
                return null;
            }
        ], (array)$replaceOptions);

        $target = replaceTextFromFormat($target, $custom_value, $replaceOptions);

        return $target;
    }


    public static function getNotifyTargetColumns($custom_table, $notify_action, array $options = [])
    {
        // get notify options by notify action
        $options = array_merge(NotifyAction::getColumnGettingOptions($notify_action), $options);
        $options = array_merge([
            'as_workflow' => false,
            'workflow' => null,
            'as_default' => true,
            'as_administrator' => false, // Only use "as_default" is false
            'as_has_roles' => false, // Only use "as_default" is false
            'as_created_user' => false, // Only use "as_default" is false
            'as_fixed_email' => true, // If true, set "FIXED_EMAIL"

            'get_email' => false, // Get email's columns.
            'get_select_table_email' => false, // Get select table where has email column
            'get_user' => false, // Get users column.
            'get_organization' => false, // Get organizations column.
            'get_custom_columns' => true,

            'get_realtion_email' => false, // Get parent 1:n, n:n, select table's relation email column.
        ], $options);

        // if (!isset($notify_action)) {
        //     return [];
        // }

        if ($options['as_default']) {
            $array = getTransArray(($options['as_workflow'] ? NotifyActionTarget::ACTION_TARGET_WORKFLOW() : NotifyActionTarget::ACTION_TARGET_CUSTOM_TABLE()), 'notify.notify_action_target_options');
        } else {
            $array = [];
            if ($options['as_administrator']) {
                $array[NotifyActionTarget::ADMINISTRATOR] = exmtrans('notify.notify_action_target_options.administrator');
            }
            if ($options['as_has_roles']) {
                $array[NotifyActionTarget::HAS_ROLES] = exmtrans('notify.notify_action_target_options.has_roles');
            }
            if ($options['as_created_user']) {
                $array[NotifyActionTarget::CREATED_USER] = exmtrans('notify.notify_action_target_options.created_user');
            }
        }

        // if $notify_action is email, set fixed email
        if ($options['as_fixed_email'] && isMatchString($notify_action, NotifyAction::EMAIL)) {
            $array[NotifyActionTarget::FIXED_EMAIL] = exmtrans('notify.notify_action_target_options.fixed_email');
        }

        $items = [];
        foreach ($array as $k => $v) {
            $items[] = ['id' => $k, 'text' => $v];
        }

        $custom_table = CustomTable::getEloquent($custom_table);

        if ($options['as_workflow']) {
            if ($options['workflow'] instanceof Workflow) {
                $workflow = $options['workflow'];
                if ($workflow->workflow_type == WorkflowType::TABLE) {
                    $workflow_tables = $workflow->workflow_tables;
                    if (!is_nullorempty($workflow_tables)) {
                        $custom_table = $workflow_tables->first()->custom_table;
                    }
                }
            }
        }

        if (!isset($custom_table)) {
            return $items;
        }

        if ($options['get_custom_columns']) {
            $custom_columns = $custom_table->custom_columns_cache;

            $column_items = [];
            foreach ($custom_columns as $custom_column) {
                if ($options['get_email']) {
                    if (ismatchString($custom_column->column_type, ColumnType::EMAIL)) {
                        $column_items[] = $custom_column;
                        continue;
                    }
                }

                if ($options['get_user']) {
                    if (ismatchString($custom_column->column_type, ColumnType::USER)) {
                        $column_items[] = $custom_column;
                        continue;
                    }
                }

                if ($options['get_organization']) {
                    if (ismatchString($custom_column->column_type, ColumnType::ORGANIZATION)) {
                        $column_items[] = $custom_column;
                        continue;
                    }
                }

                if ($options['get_select_table_email']) {
                    // if select table, getting column
                    if (ColumnType::isSelectTable($custom_column->column_type)) {
                        $select_target_table = $custom_column->select_target_table;
                        if ($select_target_table && $select_target_table->custom_columns_cache->contains(function ($custom_column) {
                            return ismatchString($custom_column->column_type, ColumnType::EMAIL);
                        })) {
                            $column_items[] = $custom_column;
                            continue;
                        }
                    }
                }
            }

            foreach ($column_items as $column_item) {
                $items[] = ['id' => $column_item->id, 'text' => exmtrans('common.custom_column') . ' : ' . $column_item->column_view_name];
            }


            // Get parent 1:n, n:n, select table's relation email column.
            if ($options['get_realtion_email'] && isMatchString($notify_action, NotifyAction::EMAIL)) {
                // get parent tables.
                $relationTables = RelationTable::getRelationTables($custom_table, false, [
                    'get_child_relation_tables' => false,
                    'get_parent_relation_tables' => true,
                ]);

                // loop $relationTables
                foreach ($relationTables as $relationTable) {
                    $relationTable->table->custom_columns_cache->each(function ($custom_column) use ($relationTable, &$items) {
                        if (!ismatchString($custom_column->column_type, ColumnType::EMAIL)) {
                            return;
                        }

                        // Set item. Contains pivot column and table.
                        if ($relationTable->searchType == SearchType::SELECT_TABLE || $relationTable->searchType == SearchType::SUMMARY_SELECT_TABLE) {
                            $view_pivot_column_id = $relationTable->selectTablePivotColumn->id;
                        } else {
                            $view_pivot_column_id = Define::PARENT_ID_NAME;
                        }
                        $text = exmtrans('common.custom_column') . ' : ' . $relationTable->table->table_view_name . ' : ' . $custom_column->column_view_name;
                        $items[] = [
                            'id' => "{$custom_column->id}?view_pivot_column_id={$view_pivot_column_id}&view_pivot_table_id={$relationTable->base_table->id}",
                            'text' => $text,
                        ];
                    });
                }
            }
        }

        return $items;
    }

    /**
     * Get User Mail Address. Only single item.
     *
     * @param $user
     * @return string|null
     * @throws \Exception
     */
    public static function getAddress($user): ?string
    {
        $result = static::getAddresses($user);
        return is_nullorempty($result) ? null : $result[0];
    }

    /**
     * Get User Mail Address list
     *
     * @param string|array|CustomValue|NotifyTarget $users
     * @return array
     */
    public static function getAddresses($users): array
    {
        // Convert "," string to array
        if (is_string($users)) {
            $users = stringToArray($users);
        } elseif (!is_list($users)) {
            $users = [$users];
        }
        $addresses = collect();
        foreach ($users as $user) {
            if ($user instanceof CustomValue) {
                $addresses->push($user->getValue('email'));
            } elseif ($user instanceof LoginUser) {
                $addresses->push($user->email);
            } elseif ($user instanceof NotifyTarget) {
                $addresses->push($user->email());
            } elseif (is_string($user)) {
                $addresses->push($user);
            } else {
                // wrong class. checking value!!
                throw new \Exception('getAddress value is wrong!');
            }
        }
        return $addresses->filter()->unique()->toArray();
    }
}
