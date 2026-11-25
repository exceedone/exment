<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Notifications\Mail\MailChannel;
use Exceedone\Exment\Notifications\Mail\MailHistory;
use Exceedone\Exment\Notifications\Mail\MailInfo;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Mails through the queue (ISS00161): the tries wait a little, a mail the user is waiting for
 * goes out at once, and a mail that could not be sent leaves a row "failed" in the send log and a
 * notice to the system administrators and the user who sent it (at most one in 10 minutes).
 * With QUEUE_CONNECTION=sync the mail is sent at once and the error reaches the caller, as before.
 */
class MailSendQueueTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        config(['mail.from.address' => 'exment-from@example.com']);
        config(['cache.default' => 'array']);
        $this->forgetNotices();
    }

    protected function tearDown(): void
    {
        $this->forgetNotices();
        parent::tearDown();
    }

    protected function forgetNotices(): void
    {
        $ids = array_merge(
            collect(System::system_admin_users())->map(function ($id) {
                return strval($id);
            })->all(),
            [TestDefine::TESTDATA_USER_LOGINID_USER2]
        );
        foreach ($ids as $id) {
            Cache::forget(MailSendJob::NOTICE_CACHE_PREFIX . $id);
        }
    }

    protected function makeJob(string $subject, ?string $userId = null): MailSendJob
    {
        $mailInfo = (new MailInfo())
            ->setFrom('exment-from@example.com')
            ->setTo(['iss161-to@example.com'])
            ->setSubject($subject)
            ->setBody('the body')
            ->setBodyType('text/plain');

        return (new MailSendJob($userId))
            ->setMailInfo($mailInfo)
            ->setMailHistory(new MailHistory());
    }

    /**
     * @return \Illuminate\Support\Collection<int, mixed>
     */
    protected function logs(string $subject)
    {
        return getModelName(SystemTableName::MAIL_SEND_LOG)::query()
            ->where('value->mail_subject', $subject)
            ->get();
    }

    protected function noticeCount(string $target_user_id): int
    {
        return NotifyNavbar::withoutGlobalScopes()
            ->where('target_user_id', $target_user_id)
            ->where('notify_id', 0)
            ->count();
    }

    public function testTheTriesWaitALittle(): void
    {
        $job = new MailSendJob();
        $this->assertSame(3, $job->tries);
        $this->assertSame([60, 300], $job->backoff());
    }

    public function testTheReasonIsShort(): void
    {
        $reason = MailSendJob::errorReason(new \RuntimeException("Connection could not be established\nwith host smtp.example.com " . str_repeat('x', 500)));
        $this->assertStringStartsWith('RuntimeException: Connection could not be established with host', $reason);
        $this->assertStringNotContainsString("\n", $reason);
        $this->assertLessThanOrEqual(MailSendJob::ERROR_REASON_LENGTH, mb_strwidth($reason));
        $this->assertSame('', MailSendJob::errorReason(null));
    }

    public function testTheLastFailureIsLoggedAndToldOnce(): void
    {
        $subject = 'iss161 failed ' . uniqid();
        $sender = TestDefine::TESTDATA_USER_LOGINID_USER2;
        $job = $this->makeJob($subject, $sender);

        $targets = $job->failureNoticeTargets();
        $this->assertContains($sender, $targets, 'the user who sent the mail');
        foreach (collect(System::system_admin_users()) as $admin) {
            $this->assertContains(strval($admin), $targets, 'a system administrator');
        }
        $this->assertSame(array_values(array_unique($targets)), $targets);

        $before = [];
        foreach ($targets as $target) {
            $before[$target] = $this->noticeCount($target);
        }

        $job->failed(new \RuntimeException('Connection refused'));

        $logs = $this->logs($subject);
        $this->assertCount(1, $logs);
        $this->assertSame(MailChannel::RESULT_FAILED, $logs->first()->getValue('send_result'));
        $this->assertStringContainsString('Connection refused', strval($logs->first()->getValue('send_error')));
        $this->assertSame('iss161-to@example.com', $logs->first()->getValue('mail_to'));
        foreach ($targets as $target) {
            $this->assertSame($before[$target] + 1, $this->noticeCount($target), "a notice to $target");
        }

        // the mail server is still down: the next mail is logged, but no more notices
        $this->makeJob($subject, $sender)->failed(new \RuntimeException('Connection refused'));
        $this->assertCount(2, $this->logs($subject));
        foreach ($targets as $target) {
            $this->assertSame($before[$target] + 1, $this->noticeCount($target), "still one notice to $target");
        }
    }

    public function testAMailWithoutHistoryLeavesNoRow(): void
    {
        $subject = 'iss161 no history ' . uniqid();
        $job = $this->makeJob($subject);
        $job->setMailHistory((new MailHistory())->setHistory(false));
        $job->failed(new \RuntimeException('Connection refused'));
        $this->assertCount(0, $this->logs($subject));
    }

    public function testTheCodeOfThe2StepVerificationGoesOutAtOnce(): void
    {
        $user = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->be($user, 'admin');
        Notification::fake();

        $method = new \ReflectionMethod(Auth2factorService::class, 'sendVerify');
        /** @var MailSender $sender */
        $sender = $method->invoke(null, MailKeyName::VERIFY_2FACTOR, ['verify_code' => '123456']);

        $jobs = Notification::sent($sender, MailSendJob::class);
        $this->assertCount(1, $jobs);
        $this->assertSame(MailSender::SEND_NOW_CONNECTION, $jobs->first()->connection);
    }

    public function testOtherMailsUseTheQueueOfTheSite(): void
    {
        Notification::fake();
        $sender = MailSender::make(null, 'iss161-to@example.com')->subject('iss161 queue')->body('the body');
        $sender->send();

        $jobs = Notification::sent($sender, MailSendJob::class);
        $this->assertCount(1, $jobs);
        $this->assertNull($jobs->first()->connection, 'the default connection of the site');
    }

    public function testSyncSendsAtOnceAndLogsSent(): void
    {
        config(['queue.default' => 'sync']);
        Mail::shouldReceive('send')->once();

        $subject = 'iss161 sync sent ' . uniqid();
        MailSender::make(null, 'iss161-to@example.com')->subject($subject)->body('the body')->send();

        $logs = $this->logs($subject);
        $this->assertCount(1, $logs);
        $this->assertSame(MailChannel::RESULT_SENT, $logs->first()->getValue('send_result'));
        $this->assertNull($logs->first()->getValue('send_error'));
    }

    public function testSyncErrorStillReachesTheCaller(): void
    {
        config(['queue.default' => 'sync']);
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('smtp is down'));

        $subject = 'iss161 sync failed ' . uniqid();
        $caught = null;
        try {
            MailSender::make(null, 'iss161-to@example.com')->subject($subject)->body('the body')->send();
        } catch (\RuntimeException $e) {
            $caught = $e;
        }
        $this->assertNotNull($caught, 'the error reaches the caller as before');
        $this->assertSame('smtp is down', $caught->getMessage());

        $logs = $this->logs($subject);
        $this->assertCount(1, $logs);
        $this->assertSame(MailChannel::RESULT_FAILED, $logs->first()->getValue('send_result'));
    }
}
