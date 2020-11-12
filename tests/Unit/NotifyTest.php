<?php

namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Jobs;
use Carbon\Carbon;

class NotifyTest extends UnitTestBase
{
    use TestTrait;

    protected function init(bool $fake)
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        if($fake){
            Notification::fake();
            Notification::assertNothingSent();
        }
    }


    public function testNotifyMail()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = 'foobar@test.com';

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == $to) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMailTemplate()
    {
        $mail_template = CustomTable::getEloquent('mail_template')->getValueModel()->where('value->mail_key_name', 'test_template_1')->first();

        $subject = $mail_template->getValue('mail_subject');
        $body = $mail_template->getValue('mail_body');
        $to = 'foobar@test.com';

        $this->_testNotifyMail([
            'mail_template' => $mail_template,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == $to) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMailTemplateParams()
    {
        $mail_template = CustomTable::getEloquent('mail_template')->getValueModel()->where('value->mail_key_name', 'test_template_2')->first();

        $subject = 'test_mail_2 AAA BBB';
        $body = $subject;
        $to = 'foobar@test.com';

        $this->_testNotifyMail([
            'mail_template' => $mail_template,
            'to' => $to,
            'prms' => [
                'prms1' => 'AAA',
                'prms2' => 'BBB',
            ],
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == $to) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMail3()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = ['foobar@test.com', 'foobar2@test.com'];

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == arrayToString($to)) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMail4()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == arrayToString(NotifyService::getAddresses($to))) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMail5()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = [CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2), CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC)];

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == arrayToString(NotifyService::getAddresses($to))) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMail6()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = NotifyTarget::getModelAsUser(CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2));

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == arrayToString(NotifyService::getAddresses($to))) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMail7()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = [NotifyTarget::getModelAsUser(CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2)), NotifyTarget::getModelAsUser(CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_DEV1_USERC))];

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == arrayToString(NotifyService::getAddresses($to))) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }

    public function testNotifyMailDisdableHistory()
    {
        $mail_template = CustomTable::getEloquent('mail_template')->getValueModel()->where('value->mail_key_name', 'test_template_1')->first();

        $subject = $mail_template->getValue('mail_subject');
        $body = $mail_template->getValue('mail_body');
        $to = 'foobar@test.com';

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
            'disableHistoryBody' => true,
        ], function($notifiable) use($to, $subject, $body) {
            return ($notifiable->getTo() == arrayToString(NotifyService::getAddresses($to))) &&
                ($notifiable->getSubject() == $subject) &&
                ($notifiable->getBody() == $body);
        });
    }
    

    public function testNotifyMailAttachment()
    {
        $subject = 'テスト';
        $body = '本文です';
        $to = 'foobar@test.com';

        // noot use archive
        \Config::set('exment.archive_attachment', false);

        // get file
        $file = Model\File::whereNotNull('parent_id')->whereNotNull('parent_type')
            ->first();
        if(!$file){
            return;
        }

        $this->_testNotifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
            'attach_files' => [$file],
        ], function($notifiable) use($to, $subject, $body, $file) {
            if(($notifiable->getTo() != $to) ||
                ($notifiable->getSubject() != $subject) ||
                ($notifiable->getBody() != $body)){
                    return false;
                };

            return count($notifiable->getAttachments()) == 1 && $notifiable->getAttachments()[0]->filename == $file->filename;
        });
    }


    public function testNotifySlack()
    {
        $this->init(true);
    
        $webhook_url = 'https://hooks.slack.com/services/XXXXX/YYYY';
        $subject = 'テスト';
        $body = '本文です';

        $notifiable = NotifyService::notifySlack([
            'webhook_url' => $webhook_url,
            'subject' => $subject,
            'body' => $body,
        ]);

        Notification::assertSentTo($notifiable, Jobs\SlackSendJob::class, 
            function($notification, $channels, $notifiable) use($webhook_url, $subject, $body) {
                return ($notifiable->getWebhookUrl() == $webhook_url) &&
                    ($notifiable->getSubject() == $subject) &&
                    ($notifiable->getBody() == $body);
            });
    }


    public function testNotifyTeams()
    {
        $this->init(true);

        $webhook_url = 'https://outlook.office.com/webhook/XXXXX/YYYYYY';
        $subject = 'テスト';
        $body = '本文です';

        $notifiable = NotifyService::notifyTeams([
            'webhook_url' => $webhook_url,
            'subject' => $subject,
            'body' => $body,
        ]);

        Notification::assertSentTo($notifiable, Jobs\MicrosoftTeamsJob::class, 
            function($notification, $channels, $notifiable) use($webhook_url, $subject, $body) {
                return ($notifiable->getWebhookUrl() == $webhook_url) &&
                    ($notifiable->getSubject() == $subject) &&
                    ($notifiable->getBody() == $body);
            });
    }

    public function testNotifyNavbar()
    {
        $this->init(false);
        
        $user = CustomTable::getEloquent('user')->getValueModel()->first();
        $subject = 'テスト';
        $body = '本文です';

        NotifyService::notifyNavbar([
            'subject' => $subject,
            'body' => $body,
            'user' => $user,
        ]);

        $data = NotifyNavbar::withoutGlobalScopes()->orderBy('created_at', 'desc')->first();
        $this->assertEquals(array_get($data, 'notify_subject'), $subject);
        $this->assertEquals(array_get($data, 'notify_body'), $body);
        $this->assertEquals(array_get($data, 'target_user_id'), $user->id);
    }

    public function testNotifyUpdate()
    {
        sleep(1);

        // Login user.
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $table_name = 'custom_value_edit_all';
        $user_id = \Exment::user()->base_user_id;
        $model = CustomTable::getEloquent($table_name)->getValueModel()
            ->where('created_user_id', '<>', $user_id)->first();
        $model->update([
            'value->text' => strrev($model->getValue('text')),
        ]);

        $data = NotifyNavbar::withoutGlobalScopes()->orderBy('created_at', 'desc')->first();
        $this->assertEquals(array_get($data, 'parent_type'), $table_name);
        $this->assertEquals(array_get($data, 'parent_id'), $model->id);
        $this->assertEquals(array_get($data, 'target_user_id'), $model->created_user_id);
        $this->assertEquals(array_get($data, 'trigger_user_id'), $user_id);
    }

    public function testNotifySchedule()
    {
        $this->init(false);

        $hh = Carbon::now()->format('G');
        $target_date = Carbon::today()->addDay(100)->format('Y-m-d');

        // Login user.
        $user_id = \Exment::user()->base_user_id;

        // change notify setting
        $notify = Notify::where('notify_trigger', NotifyTrigger::TIME)->first();
        $notify->setTriggerSetting('notify_hour', $hh);
        $notify->setTriggerSetting('notify_day', 100);
        $notify->setTriggerSetting('notify_beforeafter', -1);
        $notify->save();

        // change target data's date value
        $custom_table = CustomTable::find($notify->custom_table_id);
        $model = $custom_table->getValueModel()
            ->where('created_user_id', '<>', $user_id)->first();
        $model->update([
            'value->date' => $target_date,
        ]);

        \Artisan::call('exment:notifyschedule');

        $data = NotifyNavbar::withoutGlobalScopes()->orderBy('created_at', 'desc')->first();
        $this->assertEquals(array_get($data, 'parent_type'), $custom_table->table_name);
        $this->assertEquals(array_get($data, 'parent_id'), $model->id);
        $this->assertEquals(array_get($data, 'target_user_id'), $model->created_user_id);
        $this->assertEquals(array_get($data, 'trigger_user_id'), $user_id);
    }

    public function testNotifyButton()
    {
        $this->init(false);

        // Login user.
        $user_id = \Exment::user()->base_user_id;

        $notify = Notify::where('notify_trigger', NotifyTrigger::BUTTON)->first();
        $custom_table = CustomTable::find($notify->custom_table_id);
        $custom_value = $custom_table->getValueModel()
            ->where('created_user_id', '<>', $user_id)->first();

        // get target users
        $target_user_keys = collect();
        foreach ($notify->action_settings as $action_setting) {
            $values = $notify->getNotifyTargetUsers($custom_value, $action_setting);
            foreach ($values as $value) {
                $target_user_keys->push($value->notifyKey());
            }
        }
        $target_user_keys = $target_user_keys->unique()->toArray();

        $subject = 'テスト';
        $body = '本文です';
        $notify->notifyButtonClick($custom_value, $target_user_keys, $subject, $body);

        $data = NotifyNavbar::withoutGlobalScopes()
            ->where('notify_id', $notify->id)->orderBy('created_at', 'desc')->first();
        $this->assertEquals(array_get($data, 'parent_type'), $custom_table->table_name);
        $this->assertEquals(array_get($data, 'parent_id'), $custom_value->id);
        $this->assertEquals(array_get($data, 'target_user_id'), $custom_value->created_user_id);
        $this->assertEquals(array_get($data, 'trigger_user_id'), $user_id);
        $this->assertEquals(array_get($data, 'notify_subject'), $subject);
        $this->assertEquals(array_get($data, 'notify_body'), $body);
    }


    // Test as executeNotifyAction ----------------------------------------------------
    public function testNotifyUpdateAction()
    {
        $this->init(false);

        // Login user.
        $user = \Exment::user()->base_user;

        $notify = Notify::where('notify_trigger', NotifyTrigger::CREATE_UPDATE_DATA)->first();
        $custom_table = CustomTable::find($notify->custom_table_id);
        $custom_value = $custom_table->getValueModel()
            ->where('created_user_id', '<>', $user->id)->first();
        $target_user = CustomTable::getEloquent('user')->getValueModel(TestDefine::TESTDATA_USER_LOGINID_USER2);

        $subject = 'テスト';
        $body = '本文です';
        NotifyService::executeNotifyAction($notify, [
            'custom_value' => $custom_value,
            'subject' => $subject,
            'body' => $body,
            'user' => $target_user,
        ]);
        
        $data = NotifyNavbar::withoutGlobalScopes()
            ->where('notify_id', $notify->id)->orderBy('created_at', 'desc')->orderBy('id', 'desc')->first();
        $this->assertEquals(array_get($data, 'parent_type'), $custom_table->table_name);
        $this->assertEquals(array_get($data, 'parent_id'), $custom_value->id);
        $this->assertEquals(array_get($data, 'target_user_id'), $target_user->id);
        $this->assertEquals(array_get($data, 'trigger_user_id'), $user->id);
        $this->assertEquals(array_get($data, 'notify_subject'), $subject);
        $this->assertEquals(array_get($data, 'notify_body'), $body);
    }


    /**
     * Check notify test mail
     *
     * @return void
     */
    public function testNotifyTestMail()
    {
        $this->init(true);
        
        $notifiable = NotifyService::executeTestNotify([
            'type' => 'mail',
            'to' => TestDefine::TESTDATA_DUMMY_EMAIL,
        ]);

        Notification::assertSentTo($notifiable, Jobs\MailSendJob::class, 
            function($notification, $channels, $notifiable) {
                return ($notifiable->getTo() == TestDefine::TESTDATA_DUMMY_EMAIL) &&
                    ($notifiable->getSubject() == 'Exment TestMail') &&
                    ($notifiable->getBody() == 'Exment TestMail');
            });
    }
    
    protected function _testNotifyMail(array $params, \Closure $checkCallback)
    {
        $this->init(true);

        $notifiable = NotifyService::notifyMail($params);

        Notification::assertSentTo($notifiable, Jobs\MailSendJob::class, 
            function($notification, $channels, $notifiable) use($checkCallback) {
                return $checkCallback($notifiable);
            });
    }
}
