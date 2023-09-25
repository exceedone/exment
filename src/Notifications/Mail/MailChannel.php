<?php

namespace Exceedone\Exment\Notifications\Mail;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Services\ZipService;
use Illuminate\Notifications\Notification;
use Illuminate\Mail\Message;
use Carbon\Carbon;

class MailChannel
{
    /**
     * Send the given notification.
     *
     * @param  mixed  $notifiable
     * @param  \Illuminate\Notifications\Notification  $notification
     * @return void
     */
    public function send($notifiable, Notification $notification)
    {
        /** @var MailSendJob $notification */
        $mailMessage = $notification->toMail($notifiable);
        $this->sendMail($mailMessage);

        $this->saveHistory($mailMessage);
    }


    protected function sendMail(MailMessage $mailMessage)
    {
        // if use archive attachments, after sending, removing file
        $tmpZipPath = null;

        try {
            \Mail::send([], [], function (Message $message) use ($mailMessage, &$tmpZipPath) {
                $subject = $mailMessage->getSubject();
                $body = $mailMessage->getBody();

                $message
                    ->from($mailMessage->getFrom(), $mailMessage->getFromName())
                    ->to($mailMessage->getTo())
                    ->cc($mailMessage->getCc())
                    ->bcc($mailMessage->getBcc())
                    ->subject($mailMessage->getSubject());

                if ($mailMessage->getBodyType() == 'text/plain') {
                    $message->text($mailMessage->getBody());
                } else {
                    $message->html($mailMessage->getBody());
                }

                $this->setAttachments($message, $mailMessage, $tmpZipPath);
            });
        } finally {
            // remove file
            if (isset($tmpZipPath)) {
                \File::delete($tmpZipPath);
            }
        }
    }


    protected function setAttachments(Message $message, MailMessage $mailMessage, &$tmpZipPath)
    {
        if (collect($mailMessage->getAttachments())->count() == 0) {
            return;
        }

        // set password file
        if ($mailMessage->getUsePassword()) {
            list($filepath, $filename) = $this->archiveAttachments($mailMessage);
            $message->attach($filepath, ['as' => $filename]);
            $tmpZipPath = $filepath;
        } else {
            // attach each files
            foreach ($mailMessage->getAttachments() as $attachment) {
                $message->attachData($attachment->getFile(), $attachment->filename, ['as' => $attachment->filename]);
            }
        }
    }


    /**
     * Archive tmp attachment
     *
     * @return array offset 0 : zip path, offset 1 : filename
     */
    protected function archiveAttachments(MailMessage $mailMessage)
    {
        $password = $mailMessage->getPassword();
        $filename = Carbon::now()->format('YmdHis') . '.zip';
        $zippath = getFullpath("tmp/attachments/$filename", Define::DISKNAME_ADMIN_TMP, true);
        $tmpFolderPath = getFullpath("tmp/attachments/" . make_randomstr(10), Define::DISKNAME_ADMIN_TMP, true);

        $files = collect($mailMessage->getAttachments())->map(function ($attachment) {
            return $attachment->path;
        })->toArray();

        ZipService::createPasswordZip($files, $zippath, $tmpFolderPath, $password, Define::DISKNAME_ADMIN);

        return [$zippath, $filename];
    }


    /**
     * Save mail template history
     *
     * @param MailMessage $mailMessage
     * @return void
     */
    protected function saveHistory(MailMessage $mailMessage)
    {
        if (!$mailMessage->isSetHistory()) {
            return;
        }

        $modelname = getModelName(SystemTableName::MAIL_SEND_LOG);
        $model = new $modelname();

        // set mail info
        $model->setValue([
            'mail_from' => $mailMessage->getFrom(),
            'mail_to' => implode(",", $mailMessage->getTo()),
            'mail_cc' => implode(",", $mailMessage->getCc()),
            'mail_bcc' => implode(",", $mailMessage->getBcc()),
            'mail_subject' => $mailMessage->getSubject(),
            'send_datetime' => Carbon::now()->format('Y-m-d H:i:s'),
        ]);

        $model->setValue(
            'attachments',
            collect($mailMessage->getAttachments())
                ->implode('filename', ',')
        );

        // if security mail, set body
        if ($mailMessage->isSetHistoryBody()) {
            $model->setValue('mail_body', replaceBrTag($mailMessage->getBody()));
        } else {
            $model->setValue('mail_body', exmtrans('mail_template.disable_body'));
        }

        // set Exment data
        $model->setValue([
            'user' => $mailMessage->getUserId(),
            'parent_id' => $mailMessage->getParentId(),
            'parent_type' => $mailMessage->getParentType(),
            'mail_template' => $mailMessage->getMailTemplateId(),
        ]);

        $model->save();
    }
}
