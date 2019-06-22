<?php
namespace Exceedone\Exment\Services;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\File as ExmentFile;
use Exceedone\Exment\Form\Widgets\ModalInnerForm;

/**
 * Notify dialog, send mail etc.
 */
class NotifyService
{
    /**
     * Get dialog form for send mail
     *
     * @param Notify $notify
     * @return void
     */
    public static function getNotifyDialogForm(Notify $notify, $targetid, $tableKey, $id){
        $mail_template = $notify->getMailTemplate();
        if (!isset($mail_template)) {
            abort(404);
        }

        // create form fields
        $form = new ModalInnerForm();
        $form->disableReset();
        $form->disableSubmit();
        $form->modalAttribute('id', 'data_notify_modal');
        $form->modalHeader(exmtrans('custom_value.sendmail.title'));

        $form->action(admin_urls('data', $tableKey, $id, 'sendMail'));
    
        $form->text('mail_title', exmtrans('custom_value.sendmail.mail_title'))
            ->default(array_get($mail_template->value, 'mail_subject'))
            ->required()
            ->setWidth(8, 3);
        $form->textarea('mail_message', exmtrans('custom_value.sendmail.mail_message'))
            ->default(array_get($mail_template->value, 'mail_body'))
            ->required()
            ->setWidth(8, 3)
            ->rows(10);
        $options = ExmentFile::where('parent_type', $tableKey)
            ->where('parent_id', $id)->get()->pluck('filename', 'uuid');
        $form->multipleSelect('mail_attachment', exmtrans('custom_value.sendmail.attachment'))
            ->options($options)
            ->setWidth(8, 3);
        $form->textarea('send_error_message', exmtrans('custom_value.sendmail.send_error_message'))
            ->attribute(['readonly' => true, 'placeholder' => ''])
            ->setWidth(8, 3)
            ->rows(1)
            ->addElementClass('send_error_message');
        $form->hidden('mail_key_name')->default(array_get($mail_template->value, 'mail_key_name'));
        $form->hidden('mail_template_id')->default($targetid);

        return $form;
    }

    /**
     * send notfy mail
     *
     * @return void
     */
    public static function sendNotifyMail($custom_table){
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
