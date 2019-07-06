<?php

namespace Exceedone\Exment\Jobs;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\NotifyTarget;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Mail;
use Carbon\Carbon;

class MailSendJob extends JobBase
{
    protected $from;
    protected $to;
    protected $cc;
    protected $bcc;

    protected $subject;
    protected $body;

    protected $attachments;

    protected $mail_template;
    protected $custom_value;
    protected $user;
    protected $history_body;

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

        if (!isset($this->from)) {
            $this->from = config('mail.from.address');
        }
    }

    public function handle()
    {
        Mail::send([], [], function ($message) {
            $message->to($this->getAddress($this->to))->subject($this->subject);
            $message->from($this->getAddress($this->from));
            if (count($this->cc) > 0) {
                $message->cc($this->getAddress($this->cc));
            }
            if (count($this->bcc) > 0) {
                $message->bcc($this->getAddress($this->bcc));
            }
            // replace \r\n
            $message->setBody(preg_replace("/\r\n|\r|\n/", "<br />", $this->body), 'text/html');
            // attach files
            foreach ($this->attachments as $attachment) {
                $url = storage_paths('app', config('admin.upload.disk'), $attachment->path);
                $message->attach($url, ['as' => $attachment->filename]);
            }
        });

        $this->saveMailSendHistory();
    }
    protected function saveMailSendHistory()
    {
        $modelname = getModelName(SystemTableName::MAIL_SEND_LOG);
        $model = new $modelname;

        $model->setValue('mail_from', implode(",", $this->getAddress($this->from)) ?? null);
        $model->setValue('mail_to', implode(",", $this->getAddress($this->to)) ?? null);
        $model->setValue('mail_cc', implode(",", $this->getAddress($this->cc)) ?? null);
        $model->setValue('mail_bcc', implode(",", $this->getAddress($this->bcc)) ?? null);
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
            $model->setValue('mail_body', $this->body);
        } else {
            $model->setValue('mail_body', exmtrans('mail_template.disable_body'));
        }
        
        $model->save();
    }
    
    /**
     * Get User Mail Address
     *
     * @param [type] $users
     * @return void
     */
    protected function getAddress($users)
    {
        if (!($users instanceof Collection) && !is_array($users)) {
            $users = [$users];
        }
        $addresses = [];
        foreach ($users as $user) {
            if ($user instanceof CustomValue) {
                $addresses[] = $user->getValue('email');
            } elseif ($user instanceof NotifyTarget) {
                $addresses[] = $user->email();
            } else {
                $addresses[] = $user;
            }
        }
        // return count($addresses) == 1 ? $addresses[0] : $addresses;
        return $addresses;
    }
    
    /**
     * Get User id
     *
     * @param [type] $users
     * @return void
     */
    protected function getUserId($user)
    {
        if ($user instanceof CustomValue) {
            return $user->id;
        } elseif ($user instanceof NotifyTarget) {
            return $user->id();
        }
        // pure email
        else {
            return $user;
        }
    }
}
