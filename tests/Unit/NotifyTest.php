<?php

namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Jobs;
use Carbon\Carbon;

class NotifyTest extends UnitTestBase
{
    public function testNotifyMail()
    {
        Notification::fake();
        Notification::assertNothingSent();
    
        $subject = 'テスト';
        $body = '本文です';
        $to = 'foobar@test.com';

        $notifiable = NotifyService::notifyMail([
            'subject' => $subject,
            'body' => $body,
            'to' => $to,
        ]);

        Notification::assertSentTo($notifiable, Jobs\MailSendJob::class, 
            function($notification, $channels, $notifiable) use($to, $subject, $body) {
                return ($notifiable->getTo() == $to) &&
                    ($notifiable->getSubject() == $subject) &&
                    ($notifiable->getBody() == $body);
            });
    }

    

    public function testNotifySlack()
    {
        Notification::fake();
        Notification::assertNothingSent();
    
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
        Notification::fake();
        Notification::assertNothingSent();
    
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
        $hh = Carbon::now()->format('G');
        $target_date = Carbon::today()->addDay(100)->format('Y-m-d');

        // Login user.
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
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
        // Login user.
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
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
        // Login user.
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
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
}
