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

    protected function mailableUserCount(): int
    {
        return CustomTable::getEloquent('user')->getValueQuery()->get()
            ->filter(function ($u) {
                /** @var \Exceedone\Exment\Model\CustomValue $u */
                return !is_nullorempty($u->getValue('email'));
            })->count();
    }

    public function testSendCreatesAnswerRowsForAllUsers(): void
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

    public function testSendDispatchesJobOnlyForLinkedUsers(): void
    {
        $event = $this->createEvent();
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->linkUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2);

        $result = SafetyCheckSender::send($event);

        Bus::assertDispatchedTimes(LineSendJob::class, 2);
        $this->assertEquals(2, $result['line']);
    }

    public function testUnlinkedFlagSet(): void
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

    public function testDrillTitleHasPrefix(): void
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

    public function testResendOnlyUnanswered(): void
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

        $rowA = $rowsBefore->first(function ($row) use ($userA) {
            return (int) array_get($row->value, 'user') === $userA;
        });
        $answerTable = CustomTable::getEloquent('safety_check_answer');
        /** @var \Exceedone\Exment\Model\CustomValue $rowAFresh */
        $rowAFresh = $answerTable->getValueQuery()->find($rowA->id);
        $rowAFresh->setValue(['answer_status' => 'safe'])->save();

        $this->assertNull($event->getValue('resent_at'));

        $result = SafetyCheckSender::send($event, true);

        $this->assertEquals($userCount - 1, $result['target']);
        $this->assertEquals(1, $result['line']);

        $rowsAfter = $this->answerRows($event->id);
        $this->assertEquals($rowCountBefore, $rowsAfter->count());

        $jobsForA = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($userA) {
            return $job->getContext()['user_id'] === $userA;
        });
        $this->assertCount(1, $jobsForA);

        $jobsForB = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($userB) {
            return $job->getContext()['user_id'] === $userB;
        });
        $this->assertCount(2, $jobsForB);

        $this->assertNotNull($event->getValue('resent_at'));

        /** @var \Exceedone\Exment\Model\CustomValue $freshEvent */
        $freshEvent = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($event->id);
        $this->assertEquals($userCount, (int) $freshEvent->getValue('target_count'));

        $this->assertEquals($this->mailableUserCount(), (int) $freshEvent->getValue('sent_count'));
    }

    public function testResendCreatesMissingAnswerRowAndSends(): void
    {
        $userCount = $this->totalUserCount();
        $event = $this->createEvent();
        $userA = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $this->linkUser($userA);

        SafetyCheckSender::send($event);
        $rows = $this->answerRows($event->id);
        $this->assertEquals($userCount, $rows->count());

        $rowA = $rows->first(function ($row) use ($userA) {
            return (int) array_get($row->value, 'user') === $userA;
        });
        \DB::table(getDBTableName(CustomTable::getEloquent('safety_check_answer')))
            ->where('id', $rowA->id)->delete();
        $this->assertEquals($userCount - 1, $this->answerRows($event->id)->count());

        $result = SafetyCheckSender::send($event, true);

        $rowsAfter = $this->answerRows($event->id);
        $this->assertEquals($userCount, $rowsAfter->count(), 'Resend must recreate the missing answer row.');
        $recreated = $rowsAfter->first(function ($row) use ($userA) {
            return (int) array_get($row->value, 'user') === $userA;
        });
        $this->assertNotNull($recreated);
        $this->assertEquals('not_answered', array_get($recreated->value, 'answer_status'));

        $jobsForA = Bus::dispatched(LineSendJob::class, function (LineSendJob $job) use ($userA) {
            return $job->getContext()['user_id'] === $userA;
        });
        $this->assertCount(2, $jobsForA, 'First send + recovery resend.');
        $this->assertEquals($userCount, $result['target']);
    }

    public function testLinkedUserWithEmailAlsoReceivesMail(): void
    {
        $event = $this->createEvent();
        $linked = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        /** @var \Exceedone\Exment\Model\CustomValue $linkedValue */
        $linkedValue = CustomTable::getEloquent('user')->getValueQuery()->find($linked);
        $this->assertFalse(is_nullorempty($linkedValue->getValue('email')), 'fixture: linked user must have an email');
        $this->linkUser($linked);
        $allWithEmail = $this->mailableUserCount();

        $result = SafetyCheckSender::send($event);

        Notification::assertSentTimes(MailSendJob::class, $allWithEmail);
        $this->assertEquals($allWithEmail, $result['mail']);
        $this->assertEquals(1, $result['line']);
    }

    public function testSentCountCountsDistinctUsersAcrossChannels(): void
    {
        $event = $this->createEvent();
        $linked = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $this->linkUser($linked);
        $allWithEmail = $this->mailableUserCount();

        SafetyCheckSender::send($event);

        /** @var \Exceedone\Exment\Model\CustomValue $freshEvent */
        $freshEvent = CustomTable::getEloquent('safety_check_event')->getValueQuery()->find($event->id);
        $this->assertEquals($allWithEmail, (int) $freshEvent->getValue('sent_count'));
    }

    public function testBuildMailSenderContainsSignedUrl(): void
    {
        $event = $this->createEvent(['title' => 'Big quake']);
        /** @var \Exceedone\Exment\Model\CustomValue $userValue */
        $userValue = CustomTable::getEloquent('user')->getValueQuery()
            ->find((int) TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->assertFalse(is_nullorempty($userValue->getValue('email')));

        $sender = SafetyCheckSender::buildMailSender($userValue, $event, 'Big quake', 'body lines');
        $this->assertNotNull($sender);
        $sender->send();

        $this->assertStringContainsString('Big quake', $sender->getSubject());
        $this->assertStringContainsString('safety/answer', $sender->getBody());
        $this->assertStringContainsString('signature=', $sender->getBody());
        $this->assertStringContainsString('user=' . $userValue->id, $sender->getBody());
    }

    public function testBuildMailSenderNullWithoutEmail(): void
    {
        $event = $this->createEvent();
        $userTable = CustomTable::getEloquent('user');
        $noMail = $userTable->getValueModel();
        $noMail->setValue(['user_name' => 'nomail user', 'user_code' => 'nomail_' . time()])->save();

        /** @var \Exceedone\Exment\Model\CustomValue|null $noMailFresh */
        $noMailFresh = $userTable->getValueQuery()->find($noMail->id);
        $this->assertNull(SafetyCheckSender::buildMailSender($noMailFresh, $event, 't', 'b'));
    }

    /**
     * @return mixed
     */
    protected function finalUserOf(MailSender $sender)
    {
        $property = new \ReflectionProperty(MailSender::class, 'final_user');
        $property->setAccessible(true);
        return $property->getValue($sender);
    }

    public function testBuildMailSenderFlagsFinalUserWhenAdminTriggered(): void
    {
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN), 'admin');
        $this->assertNotNull(\Exment::getUserId(), 'Fixture: the send must be attributable to a logged-in user.');

        $event = $this->createEvent();
        /** @var \Exceedone\Exment\Model\CustomValue $userValue */
        $userValue = CustomTable::getEloquent('user')->getValueQuery()
            ->find((int) TestDefine::TESTDATA_USER_LOGINID_USER2);

        $sender = SafetyCheckSender::buildMailSender($userValue, $event, 'Big quake', 'body lines');

        $this->assertNotNull($sender);
        $this->assertTrue((bool) $this->finalUserOf($sender));
    }

    public function testBuildMailSenderLeavesFinalUserOffWhenNoLoggedInUser(): void
    {
        auth('admin')->logout();
        $this->assertNull(\Exment::getUserId(), 'Fixture: this send must be unattributable.');

        $event = $this->createEvent();
        /** @var \Exceedone\Exment\Model\CustomValue $userValue */
        $userValue = CustomTable::getEloquent('user')->getValueQuery()
            ->find((int) TestDefine::TESTDATA_USER_LOGINID_USER2);

        $sender = SafetyCheckSender::buildMailSender($userValue, $event, 'Big quake', 'body lines');

        $this->assertNotNull($sender);
        $this->assertFalse((bool) $this->finalUserOf($sender));
        $sender->send();
        Notification::assertSentTimes(MailSendJob::class, 1);
    }

    public function testResendMailsOnlyUnanswered(): void
    {
        $event = $this->createEvent();
        $linked = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $answered = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $this->linkUser($linked);
        $expectedMailFirst = $this->mailableUserCount();

        SafetyCheckSender::send($event);
        Notification::assertSentTimes(MailSendJob::class, $expectedMailFirst);

        \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::recordAnswer($event->id, $answered, 'safe', 'mail');

        $result = SafetyCheckSender::send($event, true);

        $this->assertEquals($expectedMailFirst - 1, $result['mail']);
        Notification::assertSentTimes(MailSendJob::class, $expectedMailFirst + ($expectedMailFirst - 1));
    }
}
