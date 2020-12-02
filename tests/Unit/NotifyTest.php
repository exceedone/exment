<?php

namespace Exceedone\Exment\Tests\Unit;

use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\NotifyActionTarget;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\ColumnType;
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

    // Notify target test ----------------------------------------------------

    /**
     * @return void
     */
    public function testNotifyTargetCreatedUser()
    {
        $this->_testNotifyTarget(CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT), NotifyActionTarget::CREATED_USER, function($targets, $custom_value){
            $user = CustomTable::getEloquent('user')->getValueModel($custom_value->created_user_id);
            $this->assertTrue(count($targets) == 1, 'count expects 1, but count is ' . count($targets));
            $this->assertTrue(isMatchString($user->getValue('email'), $targets[0]->email()), 'Expects  email is ' . $user->getValue('email') . ' , but result is ' . $targets[0]->email());
        });

    }


    /**
     * @return void
     */
    public function testNotifyTargetHasRoles()
    {
        $this->_testNotifyTarget(CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT), NotifyActionTarget::HAS_ROLES, function($targets, $custom_value){
            $users = $this->callStaticProtectedMethod(NotifyTarget::class, 'getModelsAsRole', $custom_value);
            $this->assertTrue(count($targets) == count($users), 'targets count is ' . count($targets) . ', but users count is ' . count($users));

            foreach($users as $user){
                $this->assertTrue(collect($targets)->contains(function($target) use($user){
                    return isMatchString($user->email(), $target->email());
                }));
            }
            foreach($targets as $target){
                $this->assertTrue(collect($users)->contains(function($user) use($target){
                    return isMatchString($user->email(), $target->email());
                }));
            }
        });
    }


    /**
     * @return void
     */
    public function testNotifyTargetEmail()
    {
        // get email column
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $email_column = $custom_table->custom_columns_cache->first(function($custom_column){
            return $custom_column->column_type == 'email';
        });

        $this->_testNotifyTarget($custom_table, $email_column, function($targets, $custom_value) use($email_column){
            $email = $custom_value->getValue($email_column);
            $this->assertTrue(count($targets) == 1, 'count expects 1, but count is ' . count($targets));
            $this->assertTrue(isMatchString($email, $targets[0]->email()), 'Expects  email is ' . $email . ' , but result is ' . $targets[0]->email());
        });
    }


    /**
     * @return void
     */
    public function testNotifyTargetUser()
    {
        // get email column
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $user_column = $custom_table->custom_columns_cache->first(function($custom_column){
            return $custom_column->column_type == ColumnType::USER && !($custom_column->getOption('multiple_enabled') ?? false);
        });

        $this->_testNotifyTarget($custom_table, $user_column, function($targets, $custom_value) use($user_column){
            $user = $custom_value->getValue($user_column);
            $email = $user->getValue('email');
            $this->assertTrue(count($targets) == 1, 'count expects 1, but count is ' . count($targets));
            $this->assertTrue(isMatchString($email, $targets[0]->email()), 'Expects  email is ' . $email . ' , but result is ' . $targets[0]->email());
        });
    }


    /**
     * @return void
     */
    public function testNotifyTargetOrganization()
    {
        // get email column
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $org_column = $custom_table->custom_columns_cache->first(function($custom_column){
            return $custom_column->column_type == ColumnType::ORGANIZATION && !($custom_column->getOption('multiple_enabled') ?? false);
        });

        $this->_testNotifyTarget($custom_table, $org_column, function($targets, $custom_value) use($org_column){
            $org = $custom_value->getValue($org_column);
            $users = $org->users;

            foreach($users as $user){
                $this->assertTrue(collect($targets)->contains(function($target) use($user){
                    return isMatchString($user->getValue('email'), $target->email());
                }));
            }
            foreach($targets as $target){
                $this->assertTrue(collect($users)->contains(function($user) use($target){
                    return isMatchString($user->getValue('email'), $target->email());
                }));
            }
        });
    }

    

    /**
     * @return void
     */
    public function testNotifyTargetSelectTable()
    {
        // get email column
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $select_table_column = $custom_table->custom_columns_cache->first(function($custom_column){
            return $custom_column->column_name == 'select_table_2';
        });

        $this->_testNotifyTarget($custom_table, $select_table_column, function($targets, $custom_value) use($select_table_column){
            $select_table_value = $custom_value->getValue($select_table_column);
            $email = $select_table_value->getValue('email');

            $this->assertTrue(count($targets) == 1, 'count expects 1, but count is ' . count($targets));
            $this->assertTrue(isMatchString($email, $targets[0]->email()), 'Expects  email is ' . $email . ' , but result is ' . $targets[0]->email());
        });
    }

    

    /**
     * @return void
     */
    protected function _testNotifyTarget(CustomTable $custom_table, $notify_action_target, \Closure $checkCallback)
    {
        $this->init(true);

        foreach([2, 1, 10] as $id){
            $custom_value = $custom_table->getValueModel($id);
            $targets = NotifyTarget::getModels(new Notify, $custom_value, $notify_action_target);
            
            $checkCallback($targets, $custom_value);
        }
    }







}
