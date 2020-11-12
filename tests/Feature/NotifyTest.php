<?php

namespace Exceedone\Exment\Tests\Feature;

use Tests\TestCase;
use Illuminate\Support\Facades\Notification;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Jobs;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Carbon\Carbon;


class NotifyTest extends TestCase
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

        Notification::assertSentTo($notifiable, Jobs\MailSendJob::class, 
            function($notification, $channels, $notifiable) use($mail_template, $user, $token) {
                return ($notifiable->getTo() == $user->email) &&
                    ($notifiable->getSubject() == $mail_template->getValue('mail_subject'))
                    //($notifiable->getBody() == $mail_template->getValue('mail_body'))
                    ;
            });
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
        $valid_period_datetime = Carbon::now()->addMinute(config('exment.login_2factor_valid_period', 10));
        $mail_template = $this->getMailTemplate(MailKeyName::VERIFY_2FACTOR);

        // execute 2factor saving data
        $this->callStaticProtectedMethod(Auth2factorService::class, 'addVerify', 'email', $verify_code, $valid_period_datetime);

        // execute notify
        $notifiable = $this->callStaticProtectedMethod(Auth2factorService::class, 'sendVerify', MailKeyName::VERIFY_2FACTOR, [
            'verify_code' => $verify_code,
            'valid_period_datetime' => $valid_period_datetime->format('Y/m/d H:i'),
        ]);

        Notification::assertSentTo($notifiable, Jobs\MailSendJob::class, 
            function($notification, $channels, $notifiable) use($mail_template, $user) {
                return ($notifiable->getTo() == $user->email) &&
                    ($notifiable->getSubject() == $mail_template->getValue('mail_subject'))
                    //($notifiable->getBody() == $mail_template->getValue('mail_body'))
                    ;
            });
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

    protected function getMailTemplate($keyName){
        return CustomTable::getEloquent('mail_template')->getValueModel()->where('value->mail_key_name', $keyName)->first();
    }

}
