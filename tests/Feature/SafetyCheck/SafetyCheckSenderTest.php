<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckSender;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;

/**
 * Task 4 - SafetyCheckSender: pre-create answer rows for all users, push a LINE Flex
 * message to linked users (LINE is the only delivery channel - unlinked users just keep
 * their `not_answered` row), and support a "re-send" mode that only targets users still
 * `not_answered` (no new rows).
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

        // sent_count must also keep reflecting the FIRST send (2 linked users), not be
        // clobbered by the smaller resend batch (1 job) -- otherwise the admin page
        // shows e.g. 送信数 1/対象 N after a resend that reached fewer users.
        $this->assertEquals(2, (int) $freshEvent->getValue('sent_count'));
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
}
