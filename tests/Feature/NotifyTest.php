<?php

namespace Exceedone\Exment\Tests\Feature;

use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Jobs;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Carbon\Carbon;

class NotifyTest extends FeatureTestBase
{
    use TestTrait;

    protected function init(bool $fake)
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));

        if ($fake) {
            Notification::fake();
            Notification::assertNothingSent();
        }
    }


    /**
     * test password notify
     *
     * @return void
     */
    public function testNotifyPasswordLoginUserCreate()
    {
        $this->_testNotifyPasswordLoginUserSaved(true);
    }


    /**
     * test password notify
     *
     * @return void
     */
    public function testNotifyPasswordLoginUserUpdate()
    {
        $this->_testNotifyPasswordLoginUserSaved(false);
    }


    /**
     * test password notify login user saved
     *
     * @return void
     */
    protected function _testNotifyPasswordLoginUserSaved(bool $is_newuser)
    {
        $this->init(true);

        $user = \Exment::user();
        $password = make_password();
        $mail_template = $this->getMailTemplate($is_newuser ? MailKeyName::CREATE_USER : MailKeyName::RESET_PASSWORD_ADMIN);

        $user->sendPassword($password);

        // execute send password
        $notifiable = $this->callProtectedMethod($user, 'send', $is_newuser);

        Notification::assertSentTo(
            $notifiable,
            Jobs\MailSendJob::class,
            function ($notification, $channels, $notifiable) use ($mail_template, $user, $password) {
                if (!isMatchString($notifiable->getTo(), $user->email) || !isMatchString($notifiable->getMailTemplateId(), $mail_template->id)) {
                    return false;
                }

                // contains token in body
                if (strpos($notifiable->getBody(), $password) === false) {
                    return false;
                }

                return true;
            }
        );
    }


    /**
     * test password reset notify
     *
     * @return void
     */
    public function testNotifyPasswordReset()
    {
        $this->init(true);

        $user = \Exment::user();
        $token = make_uuid();
        $mail_template = $this->getMailTemplate(MailKeyName::RESET_PASSWORD);

        $notifiable = $user->sendPasswordResetNotification($token);

        Notification::assertSentTo(
            $notifiable,
            Jobs\MailSendJob::class,
            function ($notification, $channels, $notifiable) use ($mail_template, $user, $token) {
                if (!isMatchString($notifiable->getTo(), $user->email) || !isMatchString($notifiable->getMailTemplateId(), $mail_template->id)) {
                    return false;
                }

                // contains token in body
                if (strpos($notifiable->getBody(), $token) === false) {
                    return false;
                }

                return true;
            }
        );
    }


    /**
     * Test 2factor mail
     *
     * @return void
     */
    public function test2factorNotify()
    {
        $this->init(true);

        $user = \Exment::user();
        $verify_code = random_int(100000, 999999);
        $valid_period_datetime = Carbon::now()->addMinutes(config('exment.login_2factor_valid_period', 10));
        $mail_template = $this->getMailTemplate(MailKeyName::VERIFY_2FACTOR);

        // execute 2factor saving data
        $this->callStaticProtectedMethod(Auth2factorService::class, 'addVerify', 'email', $verify_code, $valid_period_datetime);

        // execute notify
        $notifiable = $this->callStaticProtectedMethod(Auth2factorService::class, 'sendVerify', MailKeyName::VERIFY_2FACTOR, [
            'verify_code' => $verify_code,
            'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
        ]);

        Notification::assertSentTo(
            $notifiable,
            Jobs\MailSendJob::class,
            function ($notification, $channels, $notifiable) use ($mail_template, $user, $verify_code) {
                if (!isMatchString($notifiable->getTo(), $user->email) || !isMatchString($notifiable->getMailTemplateId(), $mail_template->id)) {
                    return false;
                }

                // contains token in $verify_code
                if (strpos($notifiable->getBody(), strval($verify_code)) === false) {
                    return false;
                }

                return true;
            }
        );
    }


    public function testNotifySchedule()
    {
        $this->init(false);

        $hh = Carbon::now()->format('G');
        $target_date = Carbon::today()->addDays(100)->format('Y-m-d');

        // Login user.
        $user_id = \Exment::user()->base_user_id;

        // change notify setting
        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::TIME)->first();
        $notify->setTriggerSetting('notify_hour', $hh);
        $notify->setTriggerSetting('notify_day', 100);
        $notify->setTriggerSetting('notify_beforeafter', -1);
        $notify->save();

        // change target data's date value
        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::find($notify->target_id);
        /** @var Model\CustomValue $model */
        $model = $custom_table->getValueModel()
            ->where('created_user_id', '<>', $user_id)->first();
        $model->update([
            'value->date' => $target_date,
        ]);

        \Artisan::call('exment:notifyschedule');

        $data = NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', $model->created_user_id)
            ->where('parent_type', $custom_table->table_name)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
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

        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::BUTTON)->first();
        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::find($notify->target_id);
        /** @var Model\CustomValue $custom_value */
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
            ->where('notify_id', $notify->id)
            ->where('target_user_id', $custom_value->created_user_id)
            ->orderBy('created_at', 'desc')
            ->orderBy('id', 'desc')
            ->first();
        $this->assertEquals(array_get($data, 'parent_type'), $custom_table->table_name);
        $this->assertEquals(array_get($data, 'parent_id'), $custom_value->id);
        $this->assertEquals(array_get($data, 'target_user_id'), $custom_value->created_user_id);
        $this->assertEquals(array_get($data, 'trigger_user_id'), $user_id);
        $this->assertEquals(array_get($data, 'notify_subject'), $subject);
        $this->assertEquals(array_get($data, 'notify_body'), $body);
    }



    public function testNotifyWorkflow()
    {
        $this->init(false);
        $user_id = \Exment::user()->base_user_id;

        /** @var Model\Workflow $workflow */
        $workflow = Model\Workflow::where('workflow_view_name', 'workflow_common_company')->first();
        $workflow_action = Model\WorkflowAction::where('action_name', 'middle_action')->where('workflow_id', $workflow->id)->first();
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);

        // create customvalue
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->setValue([
            'text' => 'test',
        ]);
        $custom_value->save();

        // execute action and create workflow value
        $workflow_value = $this->callProtectedMethod($workflow_action, 'forwardWorkflowValue', $custom_value);
        // reget custom value
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->find($custom_value->id);

        // get notify users
        $status_to = $workflow_action->getStatusToId($custom_value);
        $users = collect();
        Model\WorkflowStatus::getActionsByFrom($status_to, $workflow, true)
            ->each(function ($workflow_action) use (&$users, $custom_value) {
                $users = $users->merge(
                    $workflow_action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NOTIFY)
                );
            });
        $this->assertTrue($users->count() > 0, "Next user not contains.");

        // call notify
        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::WORKFLOW)->where('notify_view_name', 'workflow_common_company')->first();
        $notify->notifyWorkflow($custom_value, $workflow_action, $workflow_value, $status_to);

        $mail_template = $this->getMailTemplate(MailKeyName::WORKFLOW_NOTIFY);
        foreach ($users as $user) {
            $data = NotifyNavbar::withoutGlobalScopes()
                ->where('notify_id', $notify->id)
                ->where('parent_type', $custom_table->table_name)
                ->where('parent_id', $custom_value->id)
                ->where('target_user_id', $user->id)
                ->orderBy('created_at', 'desc')->get();
            $this->assertTrue($data->count() > 0, "Notify data not contains.");

            foreach ($data as $d) {
                $this->assertEquals(array_get($d, 'parent_type'), $custom_table->table_name);
                $this->assertEquals(array_get($d, 'parent_id'), $custom_value->id);
                $this->assertEquals(array_get($d, 'target_user_id'), $user->id);
                $this->assertEquals(array_get($d, 'trigger_user_id'), $user_id);
            }
        }
    }


    /**
     * Check custom value notify user only once.
     *
     * @return void
     */
    public function testNotifyCustomValueCreateOnlyOnce()
    {
        $this->init(false);

        // save custom value
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT)->getValueModel();
        $custom_value->setValue([
            'text' => 'test',
        ])->save();

        // checking notify count
        $data = NotifyNavbar::where('parent_id', $custom_value->id)
            ->where('parent_type', $custom_value->custom_table_name)
            ->get();

        $this->assertTrue($data->count() === 1, 'NotifyNavbar count excepts 1, but count is ' . $data->count());
    }

    protected function getMailTemplate($keyName)
    {
        return CustomTable::getEloquent('mail_template')->getValueModel()->where('value->mail_key_name', $keyName)->first();
    }
}
