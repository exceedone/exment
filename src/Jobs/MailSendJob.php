<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Enums;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Services\ZipService;
use Exceedone\Exment\Notifications\Mail\MailChannel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Notifications\Notification;
use Carbon\Carbon;

class MailSendJob extends Notification implements ShouldQueue
{
    use JobTrait;

    protected $from;
    protected $to;
    protected $cc;
    protected $bcc;

    protected $subject;
    protected $body;

    protected $attachments;
    protected $prms;

    protected $mail_template;
    protected $custom_value;
    protected $user;
    protected $history_body;

    protected $password;

    public function __construct($from, $to, $subject, $body, $mail_template, $options = [])
    {
        $this->from = $from;
        $this->to = $to;
        $this->subject = $subject;
        $this->body = $body;
        $this->mail_template = $mail_template;
        $this->cc = array_get($options, 'cc', []);
        $this->bcc = array_get($options, 'bcc', []);
        $this->custom_value = array_get($options, 'custom_value');
        $this->user = array_get($options, 'user');
        $this->history_body = array_get($options, 'history_body', true);
        $this->attachments = array_get($options, 'attachments', []);
        $this->prms = array_get($options, 'prms', []);

        if (!isset($this->from)) {
            $this->from = config('mail.from.address');
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * @param  mixed  $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return [MailChannel::class];
    }

    /**
     * Build the mail representation of the notification.
     *
     * @param  mixed  $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        $this->sendMail();

        if (isset($this->password)) {
            // get password notify mail template
            $mail_template = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', 'password_notify')->first();
            $subject = array_get($mail_template->value, 'mail_subject');
            $body = array_get($mail_template->value, 'mail_body');
            // replace value
            $this->prms['zip_password'] = $this->password;
            $subject = NotifyService::replaceWord($subject, $this->custom_value, $this->prms);
            $body = NotifyService::replaceWord($body, $this->custom_value, $this->prms);
            $this->sendMail($subject, $body, true);
        }

        if (isset($this->mail_template)) {
            $this->saveMailSendHistory();
        }
    }


    protected function sendMail($subject = null, $body = null, $noAttach = false)
    {
        $tmpZipPath = null;
        Mail::send([], [], function ($message) use ($subject, $body, $noAttach, &$tmpZipPath) {
            $subject = $subject ?? $this->subject;
            $body = $body ?? $this->body;

            $message->to(NotifyService::getAddress($this->to))->subject($subject);
            $message->from(NotifyService::getAddress($this->from));
            $message->cc(NotifyService::getAddress($this->cc));
            $message->bcc(NotifyService::getAddress($this->bcc));

            // set attachment
            if (!$noAttach && collect($this->attachments)->count() > 0) {
                if (boolval(config('exment.archive_attachment', false))) {
                    list($filepath, $filename) = $this->archiveAttachments();
                    $message->attach($filepath, ['as' => $filename]);
                    $tmpZipPath = $filepath;

                    // set header as password
                    $password_notify_header = getModelName(SystemTableName::MAIL_TEMPLATE)::where('value->mail_key_name', 'password_notify_header')->first();
                    if (isset($password_notify_header)) {
                        $body = array_get($password_notify_header->value, 'mail_body') . $body;
                    }
                } else {
                    // attach each files
                    foreach ($this->attachments as $attachment) {
                        $url = storage_paths('app', config('admin.upload.disk'), $attachment->path);
                        $message->attach($url, ['as' => $attachment->filename]);
                    }
                }
            }
            
            // replace \r\n
            $this->replaceAndSetBody($message, $body);
        });

        if (isset($tmpZipPath)) {
            \File::delete($tmpZipPath);
        }
    }

    /**
     * Archive tmp attachment
     *
     * @return array offset 0 : zip path, offset 1 : filename
     */
    protected function archiveAttachments()
    {
        $password = make_password(16, ['mark' => false]);
        $filename = Carbon::now()->format('YmdHis') . '.zip';
        $zippath = getFullpath("tmp/attachments/$filename", Define::DISKNAME_ADMIN_TMP, true);
        $tmpFolderPath = getFullpath("tmp/attachments/" . make_randomstr(10), Define::DISKNAME_ADMIN_TMP, true);

        $files = collect($this->attachments)->map(function ($attachment) {
            return storage_paths('app', config('admin.upload.disk'), $attachment->path);
        })->toArray();
        
        ZipService::createPasswordZip($files, $zippath, $tmpFolderPath, $password);

        $this->password = $password;

        return [$zippath, $filename];
    }
    
    protected function saveMailSendHistory()
    {
        $modelname = getModelName(SystemTableName::MAIL_SEND_LOG);
        $model = new $modelname;

        $model->setValue('mail_from', implode(",", NotifyService::getAddress($this->from)) ?? null);
        $model->setValue('mail_to', implode(",", NotifyService::getAddress($this->to)) ?? null);
        $model->setValue('mail_cc', implode(",", NotifyService::getAddress($this->cc)) ?? null);
        $model->setValue('mail_bcc', implode(",", NotifyService::getAddress($this->bcc)) ?? null);
        $model->setValue('mail_subject', $this->subject);
        $model->setValue('mail_template', $this->mail_template->id);
        $model->setValue('send_datetime', Carbon::now()->format('Y-m-d H:i:s'));
        $model->setValue('attachments', collect($this->attachments)->implode('filename', ','));
        
        if (isset($this->user)) {
            $userid =  $this->getUserId($this->user);
            $model->setValue('user', $userid);
        }

        if (isset($this->custom_value)) {
            $model->parent_id = $this->custom_value->id;
            $model->parent_type = $this->custom_value->custom_table->table_name;
        }
        
        if ($this->history_body) {
            $model->setValue('mail_body', replaceBrTag($this->body));
        } else {
            $model->setValue('mail_body', exmtrans('mail_template.disable_body'));
        }
        
        $model->save();
    }

    /**
     * Replace body break to <br/>, or <br /> to \n
     *
     * @param [type] $message
     * @param string $body
     * @return $this
     */
    protected function replaceAndSetBody($message, $body)
    {
        if (isMatchString(System::system_mail_body_type(), Enums\MailBodyType::PLAIN)) {
            $message->setBody(replaceBrTag($body), 'text/plain');
        } else {
            $message->setBody(replaceBreak($body, false), 'text/html');
        }

        return $this;
    }
    
    /**
     * Get User id
     *
     * @param mixed $user user object
     * @return string|null
     */
    protected function getUserId($user)
    {
        if ($user instanceof CustomValue) {
            return $user->getUserId();
        } elseif ($user instanceof LoginUser) {
            return $user->getUserId();
        } elseif ($user instanceof NotifyTarget) {
            return $user->id();
        }
        // pure email
        elseif (is_string($user)) {
            return $user;
        }
        return null;
    }
}
