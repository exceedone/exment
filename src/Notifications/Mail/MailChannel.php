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


    /**
     * @param MailMessage $mailMessage
     * @return void
     */
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
                    // and the same as text, for a mail program that does not show HTML (ISS02863)
                    $message->text(static::htmlToText(strval($mailMessage->getBody())));
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


    /**
     * @param Message $message
     * @param MailMessage $mailMessage
     * @param string|null $tmpZipPath
     * @return void
     */
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
     * @param MailMessage $mailMessage
     * @return array<int, string> offset 0 : zip path, offset 1 : filename
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
        // the body: unless the log management says the send log keeps no body (ISS02839)
        if ($mailMessage->isSetHistoryBody() && \Exceedone\Exment\Services\LogManagement\LogPrivacy::mailBody()) {
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

    /**
     * The text of an HTML body: a line per paragraph, line break and list item (numbered for an
     * ordered list), a link as "text (address)".
     */
    public static function htmlToText(string $html): string
    {
        $text = preg_replace_callback('#<ol[^>]*>(.*?)</ol>#is', function ($m) {
            $n = 0;
            $items = preg_replace_callback('#<li[^>]*>#i', function () use (&$n) {
                $n++;
                return "\n" . $n . '. ';
            }, $m[1]);
            return "\n" . ($items ?? $m[1]) . "\n\n";
        }, $html) ?? $html;
        $text = preg_replace('#<li[^>]*>#i', "\n- ", $text) ?? $text;
        $text = preg_replace_callback('#<a\s[^>]*href=(["\x27])(.*?)\1[^>]*>(.*?)</a>#is', function ($m) {
            $label = trim(strip_tags($m[3]));
            $href = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            return $label === '' || $label === $href ? $href : $label . ' (' . $href . ')';
        }, $text) ?? $text;
        $text = preg_replace('#<br\s*/?>#i', "\n", $text) ?? $text;
        $text = preg_replace('#</(p|div|h[1-6]|tr|ul|ol|table)>#i', "\n\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+\n/", "\n", $text) ?? $text;
        $text = preg_replace("/\n{3,}/", "\n\n", $text) ?? $text;
        return trim($text);
    }
}
