<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\PluginEventTrigger;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Widgets\ModalForm;
use Exceedone\Exment\Notifications;

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
     * @param Notify $notify
     * @return void
     */
    public function getNotifyDialogForm()
    {
        // get target users
        $users = $this->notify->getNotifyTargetUsers($this->custom_value)->filter();

        // if only one data, get form for detail
        if (count($users) <= 1) {
            return $this->getSendForm($users);
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
        foreach ($users as $user) {
            $options[$user->notifyKey()] = $user->getLabel();
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
     * @param Notify $notify
     * @return void
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
     * @return ModalForm
     */
    protected function getSendForm($notifyTargets, $isFlow = false)
    {
        $tableKey = $this->custom_table->table_name;
        $id = $this->custom_value->id;

        $mail_template = $this->notify->getMailTemplate();
        if (!isset($mail_template)) {
            abort(404);
        }

        $replace = count($notifyTargets) == 1;
        $mail_subject = array_get($mail_template->value, 'mail_subject');
        $mail_body = $mail_template->getJoinedBody();

        $notifyTarget = implode(exmtrans("common.separate_word"), collect($notifyTargets)->map(function ($notifyTarget) {
            return $notifyTarget->getLabel();
        })->toArray());
        $notifyTargetJson = json_encode(collect($notifyTargets)->map(function ($notifyTarget) {
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

        if (in_array(NotifyAction::EMAIL, $this->notify->notify_actions)) {
            $form->display(exmtrans('custom_value.sendmail.mail_to'))->default($notifyTarget);
        }
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

        if (in_array(NotifyAction::EMAIL, $this->notify->notify_actions)) {
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
     * send notfy mail
     *
     * @return void
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
        $target_user_keys = json_decode($request->get('target_users'), true);
        
        if (!isset($mail_key_name) || !isset($mail_template_id)) {
            abort(404);
        }

        $errors = [];

        if (isset($title) && isset($message)) {
            try {
                $this->notify->notifyButtonClick($this->custom_value, $target_user_keys, $title, $message, $attachments);
            } catch (\Swift_RfcComplianceException $ex) {
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['send_error_message' => ['type' => 'input',
                        'message' => exmtrans('error.mailsend_failed')
                    ]],
                ]);
            } catch (\Exception $ex) {
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
     * Execute Notify test
     *
     * @param array $params
     * @return void
     */
    public static function executeTestNotify($params = [])
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
            Notifications\MailSender::make(null, $to)
                ->subject($subject)
                ->body($body)
                ->send();
        }
        // throw mailsend Exception
        catch (\Swift_TransportException $ex) {
            throw $ex;
        }
    }
    
    /**
     * Execute Notify action
     *
     * @param [type] $notify
     * @param array $params
     * @return void
     */
    public static function executeNotifyAction($notify, $params = [])
    {
        $params = array_merge(
            [
                'mail_template' => null,
                'custom_value' => null,
                'subject' => null,
                'body' => null,
                'is_chat' => false,
            ],
            $params
        );
        $params['notify'] = $notify;
        $custom_value = $params['custom_value'];

        // get notify actions
        $notify_actions = $notify->notify_actions;
        foreach ($notify_actions as $notify_action) {
            if (NotifyAction::isChatMessage($notify_action) != $params['is_chat']) {
                continue;
            }
            Plugin::pluginExecuteEvent(PluginEventTrigger::NOTIFY_EXECUTING, $custom_value->custom_table, [
                'custom_table' => $custom_value->custom_table,
                'custom_value' => $custom_value,
                'notify' => $notify,
            ]);
            switch ($notify_action) {
                case NotifyAction::EMAIL:
                    static::notifyMail($params);
                    break;

                case NotifyAction::SHOW_PAGE:
                    static::notifyNavbar($params);
                    break;

                case NotifyAction::SLACK:
                    // replace word
                    static::notifySlack($params);
                    break;
    
                case NotifyAction::MICROSOFT_TEAMS:
                    // replace word
                    static::notifyTeams($params);
                    break;
            }

            Plugin::pluginExecuteEvent(PluginEventTrigger::NOTIFY_EXECUTED, $custom_value->custom_table, [
                'custom_table' => $custom_value->custom_table,
                'custom_value' => $custom_value,
                'notify' => $notify,
            ]);
            // call notified trigger operations
            //CustomOperation::operationExecuteEvent(CustomOperationType::NOTIFIED, $custom_value, true);
        }
    }

    /**
     * Notify email
     *
     * @param array $params
     * @return void
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
                'replaceOptions' => [],
            ],
            $params
        );
        static::replaceSubjectBody($params);

        // send mail
        try {
            Notifications\MailSender::make($params['mail_template'], $params['user'] ?? $params['to'])
            ->prms($params['prms'])
            ->user($params['user'])
            ->to($params['to'])
            ->custom_value($params['custom_value'])
            ->subject($params['subject'])
            ->body($params['body'])
            ->cc($params['cc'])
            ->bcc($params['bcc'])
            ->attachments($params['attach_files'])
            ->replaceOptions($params['replaceOptions'])
            ->send();
        }
        // throw mailsend Exception
        catch (\Swift_TransportException $ex) {
            throw $ex;
        }
    }


    /**
     * Notify navbar
     *
     * @param array $params
     * @return void
     */
    public static function notifyNavbar(array $params = [])
    {
        $params = array_merge(
            [
                'notify' => null,
                'mail_template' => null,
                'prms' => [],
                'user' => null,
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
        $subject = $params['subject'];
        $body = $params['body'];
        $replaceOptions = $params['replaceOptions'];


        if ($user instanceof CustomValue) {
            $id = $user->getUserId();
        } elseif ($user instanceof NotifyTarget) {
            $id = $user->id();
        } elseif (is_numeric($user)) {
            $id = $user;
        }

        if (!isset($id)) {
            return;
        }

        // save data
        $login_user = \Exment::user();

        // replace system:site_name to custom_value label
        if (isset($custom_value)) {
            array_set($prms, 'system.site_name', $custom_value->label);
        }

        // replace value
        $mail_subject = static::replaceWord($subject, $custom_value, $prms, $replaceOptions);
        $mail_body = static::replaceWord($body, $custom_value, $prms, $replaceOptions);

        $notify_navbar = new NotifyNavbar;
        $notify_navbar->notify_id = array_get($notify, 'id', -1);

        if (isset($custom_value)) {
            $notify_navbar->parent_id = array_get($custom_value, 'id');
            $notify_navbar->parent_type = $custom_value->custom_table->table_name;
        }

        $notify_navbar->notify_subject = $mail_subject;
        $notify_navbar->notify_body = $mail_body;
        $notify_navbar->target_user_id = $id;
        $notify_navbar->trigger_user_id = isset($login_user) ? $login_user->getUserId() : null;
        $notify_navbar->save();
    }



    /**
     * Notify slack
     *
     * @param array $params
     * @return void
     */
    public static function notifySlack(array $params = [])
    {
        return static::notifyWebHook($params, Notifications\SlackSender::class);
    }


    /**
     * Notify teams
     *
     * @param array $params
     * @return void
     */
    public static function notifyTeams(array $params = [])
    {
        return static::notifyWebHook($params, Notifications\MicrosoftTeamsSender::class);
    }


    protected static function notifyWebHook(array $params, string $className)
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
            ],
            $params
        );
        static::replaceSubjectBody($params);

        $webhook_url = $params['webhook_url'];
        if (!isset($webhook_url) && isset($params['notify'])) {
            $webhook_url = array_get($params['notify']->action_settings, 'webhook_url');
        }

        // replace word
        $slack_subject = static::replaceWord($params['subject'], $params['custom_value'], $params['prms'], $params['replaceOptions']);
        $slack_body = static::replaceWord($params['body'], $params['custom_value'], $params['prms'], $params['replaceOptions']);

        // send message
        $options = ['webhook_name' => $params['webhook_name'], 'webhook_icon' => $params['webhook_icon']];
        $className::make($webhook_url, $slack_subject, $slack_body, $options)->send();
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
            ],
            $params
        );
        $notify = $params['notify'];
        $mail_template = $params['mail_template'];

        // get template
        if (!isset($mail_template) && isset($notify)) {
            $mail_template = array_get($notify->action_settings, 'mail_template_id');
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
    }

    /**
     * get Progress Info
     *
     * @param [type] $isSelectTarget
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
}
