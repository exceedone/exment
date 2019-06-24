<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Widgets\ModalInnerForm;

/**
 * Notify dialog, send mail etc.
 */
class NotifyService
{
    protected $notify;
    
    protected $targetid;

    protected $custom_table;
    
    protected $custom_value;

    public function __construct(Notify $notify, $targetid, $tableKey, $id){
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
    public function getNotifyDialogForm(){
        // get target users
        $users = $this->notify->getNotifyTargetUsers($this->custom_value);

        // if only one data, get form for detail
        if(count($users) == 1){
            return $this->getSendForm($users);
        }
        
        // create form fields
        $tableKey = $this->custom_table->table_name;
        $id = $this->custom_value->id;

        $form = new ModalInnerForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_notify_modal');
        $form->modalHeader(exmtrans('custom_value.sendmail.title'));
        $form->action(admin_urls('data', $tableKey, $id, 'sendTargetUsers'));

        $options = [];
        foreach($users as $user){
            $options[$user->notifyKey()] = $user->getLabel();
        }

        // select target users
        $form->listbox('target_users', exmtrans('custom_value.sendmail.mail_to'))
            ->required()
            ->options($options)
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
    public function getNotifyDialogFormMultiple(){
        // get target users
        $target_users = request()->get('target_users');

        $users = [];
        foreach($target_users as $target_user){
            // get definition target users
            if(!is_null($user = NotifyTarget::getSelectedNotifyTarget($target_user, $this->notify, $this->custom_value))){
                $users[] = $user;
            }
        }

        return $this->getSendForm($users);
    }

    /**
     * Get Send Form. if only one user, Replace format.
     *
     * @return void
     */
    protected function getSendForm($notifyTargets){
        $tableKey = $this->custom_table->table_name;
        $id = $this->custom_value->id;

        $mail_template = $this->notify->getMailTemplate();
        if (!isset($mail_template)) {
            abort(404);
        }

        $replace = count($notifyTargets) == 1;
        $mail_subject = array_get($mail_template->value, 'mail_subject');
        $mail_body = array_get($mail_template->value, 'mail_body');
        $notifyTarget = implode(exmtrans("common.separate_word"), collect($notifyTargets)->map(function($notifyTarget){
            return $notifyTarget->getLabel();
        })->toArray());

        if($replace){
            $mail_subject = replaceTextFromFormat($mail_subject, $this->custom_value);
            $mail_body = replaceTextFromFormat($mail_body, $this->custom_value);
        }

        // create form fields
        $form = new ModalInnerForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_notify_modal');
        $form->modalHeader(exmtrans('custom_value.sendmail.title'));
        $form->action(admin_urls('data', $tableKey, $this->custom_value->id, 'sendMail'));

        $form->display(exmtrans('custom_value.sendmail.mail_to'))->default($notifyTarget);

        $form->text('mail_title', exmtrans('custom_value.sendmail.mail_title'))
            ->default($mail_subject)
            ->required();

        $form->textarea('mail_message', exmtrans('custom_value.sendmail.mail_message'))
            ->default($mail_body)
            ->required()
            ->rows(10);

        $options = ExmentFile::where('parent_type', $tableKey)
            ->where('parent_id', $id)->get()->pluck('filename', 'uuid');

        $form->multipleSelect('mail_attachment', exmtrans('custom_value.sendmail.attachment'))
            ->options($options);

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
    public function sendNotifyMail($custom_table){
        $request = request();

        $title = $request->get('mail_title');
        $message = $request->get('mail_message');
        $attachments = $request->get('mail_attachment');
        $mail_key_name = $request->get('mail_key_name');
        $mail_template_id = $request->get('mail_template_id');

        if (!isset($mail_key_name) || !isset($mail_template_id)) {
            abort(404);
        }

        $errors = [];

        if (isset($title) && isset($message)) {
            try {
                $notify = Notify::where('suuid', $mail_template_id)->first();
                $custom_value = $custom_table->getValueModel($id);
                $notify->notifyButtonClick($custom_value, $title, $message, $attachments);
            } catch(Exception $ex) {
                return getAjaxResponse([
                    'result'  => false,
                    'errors' => ['send_error_message' => ['type' => 'input', 
                        'message' => exmtrans('custom_value.sendmail.message.send_error')]],
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
}
