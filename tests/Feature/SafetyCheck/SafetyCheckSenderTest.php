<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Jobs\MailSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Notifications\MailSender;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;

/**
 * Task 4 - SafetyCheckSender: pre-create answer rows for all users, then deliver over
 * TWO channels - a LINE Flex push for users who linked their LINE account, and a
 * mail carrying a signed web-answer URL for EVERY user with an email, linked or not
 * (both channels per user since 2026-08-28; see SafetyCheckAnswerController). A user
 * with neither a LINE link nor an email keeps
 * their `not_answered` row, flagged `unlinked_flg` so the admin sees the gap. Also
 * supports a "re-send" mode that only targets users still `not_answered`, recreating any
 * answer row that failed to be created at an earlier send.
 */
class SafetyCheckSenderTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        Bus::fake([LineSendJob::class]);
        Notification::fake();
    }

    /** Users who get the mail: everyone with an email (LINE-linked included, 2026-08-28). */
    protected function mailableUserCount(): int
    {
        return CustomTable::getEloquent('user')->getValueQuery()->get()
            ->filter(function ($u) {
                return !is_nullorempty($u->getValue('email'));
            })->count();
    }

    public function testSendCreatesAnswerRowsForAllUsers()
    {
        $event = $this->createEvent();
        $userCount = $this->totalUserCount();

        SafetyCheckSender::send($event);

        $rows = $this->answerRows($event->id);
        $this->assertEquals($userCount, $rows->count());
        foreach ($rows as $row) {
            $this->assertEquals('not_answered', array_get($row->value, 'answer_status'));
        }
    }

    public function testSendDispatchesJobOnlyForLinkedUsers()
    {
        $event = $this->createEvent();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2);

        $result = SafetyCheckSender::send($event);

        Bus::assertDispatchedTimes(LineSendJob::class, 2);
        $this->assertEquals(2, $result['line']);
    }

    public function testUnlinkedFlagSet()
    {
        $event = $this->createEvent();
        $linkedUserId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $unlinkedUserId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $this->linkUser($linkedUserId);

        SafetyCheckSender::send($event);

        $rows = $this->answerRows($event->id)->keyBy(function ($row) {
            return (int) array_get($row->value, 'user');
        });

        $this->assertFalse(boolval(array_get($rows->get($linkedUserId)->value, 'unlinked_flg')));
        $this->assertTrue(boolval(array_get($rows->get($unlinkedUserId)->value, 'unlinked_flg')));
    }

    public function testDrillTitleHasPrefix()
    {
        $event = $this->createEvent(['trigger_type' => 'drill', 'title' => 'Earthquake Drill']);
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        SafetyCheckSender::send($event);

        $prefix = exmtrans('safety.drill_prefix');
        Bus::assertDispatched(LineSendJob::class, function (LineSendJob $job) use ($prefix) {
            $subject = $job->getContext()['subject'] ?? '';
            return strpos($subject, $prefix) === 0;
        });
    }

    public function testResendOnlyUnanswered()
    {
        $userCount = $this->totalUserCount();
        $event = $this->createEvent();
        $userA = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $userB = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $this->linkUser($userA);
        $this->linkUser($userB);

        SafetyCheckSender::send($event);

        $rowsBefore = $this->answerRows($event->id);
        $rowCountBefore = $rowsBefore->count();
        $this->assertEquals($userCount, $rowCountBefore);

        // user A answers "safe" -> must be excluded from the re-send
        $rowA = $rowsBefore->first(function ($row) use ($userA) {
            return (int) array_get($row->value, 'user') === $userA;
        });
        $answerTable = CustomTable::getEloquent('safety_check_answer');
        $answerTable->getValueQuery()->find($rowA->id)->setValue(['answer_status' => 'safe'])->save();

        $this->assertNull($event->getValue('resent_at'));

        $result = SafetyCheckSender::send($event, true);

        // still-unanswered users (everyone except A) are the target of the re-send
        $this->assertEquals($userCount - 1, $result['target']);
        // only B is linked among the still-unanswered users
        $this->assertEquals(1, $result['line']);

        // no new rows created by the re-send
        $rowsAfter = $this->answerRows($event->id);
        $this->assertEquals($rowCountBefore, $rowsAfter->count());

        // A got a job only from the first send; B got one from each send
        $jobsForA = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($userA) {
            return $job->getContext()['user_id'] === $userA;
        });
        $this->assertCount(1, $jobsForA);

        $jobsForB = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($userB) {
            return $job->getContext()['user_id'] === $userB;
        });
        $this->assertCount(2, $jobsForB);

        $this->assertNotNull($event->getValue('resent_at'));

        // target_count must keep reflecting the ORIGINAL audience size, not the smaller
        // still-unanswered subset re-send targeted -- otherwise the admin page can show
        // 回答数 (answered count) > 対象者数 (target count).
        $freshEvent = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($event->id);
        $this->assertEquals($userCount, (int) $freshEvent->getValue('target_count'));

        // sent_count reflects the FIRST send, not clobbered by the smaller resend
        // batch (1 job) -- otherwise the admin page shows e.g. 送信数 1/対象 N after
        // a resend that reached fewer users. Value = DISTINCT users reached at the
        // first send; A and B both have email, so "everyone with email" covers all.
        $this->assertEquals($this->mailableUserCount(), (int) $freshEvent->getValue('sent_count'));
    }

    /**
     * A user whose answer row failed to create at the first send (logged, skipped)
     * would otherwise be invisible in 未回答 and unreachable forever. Resend must
     * CREATE the missing row and send to that user, making 再送 the self-healing
     * recovery path.
     */
    public function testResendCreatesMissingAnswerRowAndSends()
    {
        $userCount = $this->totalUserCount();
        $event = $this->createEvent();
        $userA = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $this->linkUser($userA);

        SafetyCheckSender::send($event);
        $rows = $this->answerRows($event->id);
        $this->assertEquals($userCount, $rows->count());

        // simulate "row create failed at first send": user A's row does not exist
        $rowA = $rows->first(function ($row) use ($userA) {
            return (int) array_get($row->value, 'user') === $userA;
        });
        \DB::table(getDBTableName(CustomTable::getEloquent('safety_check_answer')))
            ->where('id', $rowA->id)->delete();
        $this->assertEquals($userCount - 1, $this->answerRows($event->id)->count());

        $result = SafetyCheckSender::send($event, true);

        // the missing row is recreated as not_answered
        $rowsAfter = $this->answerRows($event->id);
        $this->assertEquals($userCount, $rowsAfter->count(), 'Resend must recreate the missing answer row.');
        $recreated = $rowsAfter->first(function ($row) use ($userA) {
            return (int) array_get($row->value, 'user') === $userA;
        });
        $this->assertNotNull($recreated);
        $this->assertEquals('not_answered', array_get($recreated->value, 'answer_status'));

        // and user A (linked, not answered) got a LINE job from the resend
        $jobsForA = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($userA) {
            return $job->getContext()['user_id'] === $userA;
        });
        $this->assertCount(2, $jobsForA, 'First send + recovery resend.');
        $this->assertEquals($userCount, $result['target']);
    }

    /**
     * 2026-08-28 behavior change: mail is no longer fallback-only — EVERY user
     * with an email gets the mail, LINE-linked included (they get both channels).
     */
    public function testLinkedUserWithEmailAlsoReceivesMail()
    {
        $event = $this->createEvent();
        $linked = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $linkedValue = CustomTable::getEloquent('user')->getValueQuery()->find($linked);
        $this->assertFalse(is_nullorempty($linkedValue->getValue('email')), 'fixture: linked user must have an email');
        $this->linkUser($linked);
        $allWithEmail = $this->mailableUserCount();

        $result = SafetyCheckSender::send($event);

        // the linked user is IN the mail count now, not excluded from it
        Notification::assertSentTimes(MailSendJob::class, $allWithEmail);
        $this->assertEquals($allWithEmail, $result['mail']);
        $this->assertEquals(1, $result['line']);
    }

    /**
     * sent_count stays "how many USERS were reached" — a user reached over both
     * channels counts once, so line+mail (which double-counts them) must NOT be
     * what gets stored.
     */
    public function testSentCountCountsDistinctUsersAcrossChannels()
    {
        $event = $this->createEvent();
        $linked = (int) TestDefine::TESTDATA_USER_LOGINID_USER1; // has email (guarded above)
        $this->linkUser($linked);
        $allWithEmail = $this->mailableUserCount();

        SafetyCheckSender::send($event);

        $freshEvent = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($event->id);
        // linked user already among the mailable ones -> distinct = allWithEmail
        $this->assertEquals($allWithEmail, (int) $freshEvent->getValue('sent_count'));
    }

    public function testBuildMailSenderContainsSignedUrl()
    {
        $event = $this->createEvent(['title' => 'Big quake']);
        $userValue = CustomTable::getEloquent('user')->getValueQuery()
            ->find((int) TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertFalse(is_nullorempty($userValue->getValue('email')));

        $sender = SafetyCheckSender::buildMailSender($userValue, $event, 'Big quake', 'body lines');
        $this->assertNotNull($sender);
        $sender->send();

        // sau send(), subject/body đã được replaceWord (xem NotifyTest pattern)
        $this->assertStringContainsString('Big quake', $sender->getSubject());
        $this->assertStringContainsString('safety/answer', $sender->getBody());
        $this->assertStringContainsString('signature=', $sender->getBody());
        $this->assertStringContainsString('user=' . $userValue->id, $sender->getBody());
    }

    public function testBuildMailSenderNullWithoutEmail()
    {
        $event = $this->createEvent();
        $userTable = CustomTable::getEloquent('user');
        $noMail = $userTable->getValueModel();
        $noMail->setValue(['user_name' => 'nomail user', 'user_code' => 'nomail_' . time()])->save();

        $this->assertNull(SafetyCheckSender::buildMailSender($userTable->getValueQuery()->find($noMail->id), $event, 't', 'b'));
    }

    /** MailSender exposes no getter for final_user; read the protected property. */
    protected function finalUserOf(MailSender $sender)
    {
        $property = new \ReflectionProperty(MailSender::class, 'final_user');
        $property->setAccessible(true);
        return $property->getValue($sender);
    }

    /**
     * A fallback mail that dies on a real queue is otherwise invisible: the
     * mail_send_log row is only written on a SUCCESSFUL send, and the try/catch
     * around send() in SafetyCheckSender::send() cannot see a job that failed on
     * a worker minutes later. MailSendJob::failed() is the only remaining trace,
     * and it emits the 'sendmail_error' navbar notice to the triggering admin
     * ONLY when the sender flagged final_user. So an admin-triggered send must
     * set it.
     */
    public function testBuildMailSenderFlagsFinalUserWhenAdminTriggered()
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        $this->assertNotNull(\Exment::getUserId(), 'Fixture: the send must be attributable to a logged-in user.');

        $event = $this->createEvent();
        $userValue = CustomTable::getEloquent('user')->getValueQuery()
            ->find((int) TestDefine::TESTDATA_USER_LOGINID_USER2);

        $sender = SafetyCheckSender::buildMailSender($userValue, $event, 'Big quake', 'body lines');

        $this->assertNotNull($sender);
        $this->assertTrue((bool) $this->finalUserOf($sender));
    }

    /**
     * ...but a send with NO logged-in user (JMA auto-trigger, CLI/scheduler) must
     * leave the flag off. NavbarJob writes notify_navbar.target_user_id from the
     * user id captured at send time, and that column is NOT NULL — flagging an
     * unattributable send would only make failed() throw. Those sends stay
     * untraced for now (documented limitation), and building/sending one must
     * still not throw.
     */
    public function testBuildMailSenderLeavesFinalUserOffWhenNoLoggedInUser()
    {
        auth('admin')->logout();
        $this->assertNull(\Exment::getUserId(), 'Fixture: this send must be unattributable.');

        $event = $this->createEvent();
        $userValue = CustomTable::getEloquent('user')->getValueQuery()
            ->find((int) TestDefine::TESTDATA_USER_LOGINID_USER2);

        $sender = SafetyCheckSender::buildMailSender($userValue, $event, 'Big quake', 'body lines');

        $this->assertNotNull($sender);
        $this->assertFalse((bool) $this->finalUserOf($sender));
        $sender->send();
        Notification::assertSentTimes(MailSendJob::class, 1);
    }

    public function testResendMailsOnlyUnanswered()
    {
        $event = $this->createEvent();
        $linked = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $answered = (int) TestDefine::TESTDATA_USER_LOGINID_USER2; // unlinked, sẽ trả lời
        $this->linkUser($linked);
        $expectedMailFirst = $this->mailableUserCount(); // linked user1 gets mail too now

        SafetyCheckSender::send($event);
        Notification::assertSentTimes(MailSendJob::class, $expectedMailFirst);

        // user2 trả lời qua web -> resend không gửi lại cho user2
        \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::recordAnswer($event->id, $answered, 'safe', 'mail');

        $result = SafetyCheckSender::send($event, true);

        $this->assertEquals($expectedMailFirst - 1, $result['mail']);
        Notification::assertSentTimes(MailSendJob::class, $expectedMailFirst + ($expectedMailFirst - 1));
    }
}
