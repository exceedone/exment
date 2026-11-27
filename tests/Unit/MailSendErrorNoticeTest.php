<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Notifications\Mail\MailHistory;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * When a mail cannot be sent, the user gets a notice (the mail template "sendmail_error").
 * The notice was saved with notify_id -1, which the unsigned column refuses on MySQL.
 */
class MailSendErrorNoticeTest extends UnitTestBase
{
    use DatabaseTransactions;

    public function testAFailedMailLeavesANotice(): void
    {
        $user_id = TestDefine::TESTDATA_USER_LOGINID_USER2;
        $count = function () use ($user_id) {
            return NotifyNavbar::withoutGlobalScopes()
                ->where('target_user_id', $user_id)
                ->where('notify_id', 0)
                ->count();
        };
        $before = $count();

        $mailInfo = (new MailInfo())
            ->setFrom('exment-from@example.com')
            ->setTo(['sendmail-error@example.com'])
            ->setSubject('sendmail_error notice')
            ->setBody('the body')
            ->setBodyType('text/plain');
        $job = (new MailSendJob($user_id, true))
            ->setMailInfo($mailInfo)
            ->setMailHistory(new MailHistory());

        $job->failed(new \RuntimeException('Connection refused'));

        $this->assertSame($before + 1, $count());
    }
}
