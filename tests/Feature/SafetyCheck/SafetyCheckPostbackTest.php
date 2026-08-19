<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Task 6 - Webhook postback act=safety: taps on the LINE Flex "safe / minor_injury /
 * need_help" buttons record the answer onto the matching safety_check_answer row.
 *
 * Exercises the real public route admin/line/webhook with a valid signature
 * (same conventions as LineWebhookTest / LinePostbackWorkflowTest).
 */
class SafetyCheckPostbackTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;
    use SafetyCheckTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        SafetyCheckInstaller::ensureAll();
        $this->setUpLineWebhookMock();
    }

    // -------------------------------------------------- helpers

    protected function postbackEvent(string $data, string $lineUserId, string $replyToken = 'rt-safety', array $overrides = []): array
    {
        return array_merge([
            'type' => 'postback',
            'replyToken' => $replyToken,
            'source' => ['userId' => $lineUserId],
            'postback' => ['data' => $data],
        ], $overrides);
    }

    // -------------------------------------------------- tests

    public function testTapSafeRecordsAnswerAndReplies()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $data = 'act=safety&event=' . $event->id . '&st=safe';
        $this->postWebhook(['events' => [$this->postbackEvent($data, $lineUserId)]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('safe', array_get($fresh->value, 'answer_status'));
        $this->assertNotNull(array_get($fresh->value, 'answered_at'), 'answered_at must be set.');
        $this->assertEquals('line', array_get($fresh->value, 'channel'));

        $expected = exmtrans('safety.answer_done', ['status' => exmtrans('safety.status_safe')]);
        $this->assertEquals($expected, $this->lastReplyText());
    }

    public function testTapNeedHelpChangesExistingAnswer()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId, ['answer_status' => 'safe']);

        $data = 'act=safety&event=' . $event->id . '&st=need_help';
        $this->postWebhook(['events' => [$this->postbackEvent($data, $lineUserId)]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('need_help', array_get($fresh->value, 'answer_status'), 'A repeat tap must be able to change the recorded status.');

        $expected = exmtrans('safety.answer_done', ['status' => exmtrans('safety.status_need_help')]);
        $this->assertEquals($expected, $this->lastReplyText());
    }

    public function testEventClosedLeavesRowUnchangedAndRepliesClosed()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent(['event_status' => 'closed']);
        $row = $this->createAnswerRow($event->id, $userId);

        $data = 'act=safety&event=' . $event->id . '&st=safe';
        $this->postWebhook(['events' => [$this->postbackEvent($data, $lineUserId)]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('not_answered', array_get($fresh->value, 'answer_status'), 'A closed event must not change the row.');
        $this->assertNull(array_get($fresh->value, 'answered_at'));

        $this->assertEquals(exmtrans('safety.answer_closed'), $this->lastReplyText());
    }

    public function testUnlinkedLineUserLeavesRowUnchangedAndRepliesNotLinked()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);
        // deliberately no LineAccountLink for this LINE user id

        $data = 'act=safety&event=' . $event->id . '&st=safe';
        $this->postWebhook(['events' => [$this->postbackEvent($data, 'Uunlinkedsafety')]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('not_answered', array_get($fresh->value, 'answer_status'), 'An unlinked LINE user must not change the row.');

        $this->assertEquals(exmtrans('line.account_not_linked'), $this->lastReplyText());
    }

    public function testInvalidStatusLeavesRowUnchangedAndRepliesInvalid()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $data = 'act=safety&event=' . $event->id . '&st=hacked';
        $this->postWebhook(['events' => [$this->postbackEvent($data, $lineUserId)]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('not_answered', array_get($fresh->value, 'answer_status'), 'An invalid st value must not change the row.');

        $this->assertEquals(exmtrans('line.invalid_action_data'), $this->lastReplyText());
    }

    /**
     * user2 belongs to role group "user_group" which has NO permission on the
     * safety_check_* tables (the installer grants none). The postback must still
     * record the answer: identity is already proven by the signed webhook +
     * LineAccountLink chain, and the row touched is the user's own.
     * Regression test for CustomValueModelScope forcing `id < 0` (record_not_found).
     */
    public function testRegularUserWithoutTablePermissionCanAnswer()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $data = 'act=safety&event=' . $event->id . '&st=need_help';
        $this->postWebhook(['events' => [$this->postbackEvent($data, $lineUserId)]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('need_help', array_get($fresh->value, 'answer_status'), 'A user without table permission must still be able to answer their own row.');
        $this->assertEquals('line', array_get($fresh->value, 'channel'));

        $expected = exmtrans('safety.answer_done', ['status' => exmtrans('safety.status_need_help')]);
        $this->assertEquals($expected, $this->lastReplyText());
    }

    /**
     * LINE may redeliver a webhook, and redelivery order is not guaranteed. A
     * redelivered old "safe" tap must NOT overwrite a newer "need_help" answer:
     * events carrying a webhookEventId are processed exactly once.
     */
    public function testRedeliveredWebhookEventDoesNotOverwriteNewerAnswer()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        $safeEvent = $this->postbackEvent(
            'act=safety&event=' . $event->id . '&st=safe',
            $lineUserId,
            'rt-first',
            ['webhookEventId' => 'WH-safe-1', 'deliveryContext' => ['isRedelivery' => false]]
        );
        $this->postWebhook(['events' => [$safeEvent]])->assertStatus(200);
        $this->assertEquals('safe', array_get($this->freshAnswerRow($row->id)->value, 'answer_status'));

        // the user changes their answer to need_help (a different webhook event)
        $helpEvent = $this->postbackEvent(
            'act=safety&event=' . $event->id . '&st=need_help',
            $lineUserId,
            'rt-second',
            ['webhookEventId' => 'WH-help-2', 'deliveryContext' => ['isRedelivery' => false]]
        );
        $this->postWebhook(['events' => [$helpEvent]])->assertStatus(200);
        $this->assertEquals('need_help', array_get($this->freshAnswerRow($row->id)->value, 'answer_status'));

        // LINE redelivers the ORIGINAL safe event (same webhookEventId)
        $redelivered = $safeEvent;
        $redelivered['deliveryContext'] = ['isRedelivery' => true];
        $this->postWebhook(['events' => [$redelivered]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('need_help', array_get($fresh->value, 'answer_status'), 'A redelivered older webhook event must not overwrite the newer answer.');
    }

    /**
     * The exactly-once guard must not turn a FAILED processing attempt into a
     * permanent skip: when handling throws (e.g. a transient DB error), the
     * webhookEventId has to be released again so LINE's redelivery of the same
     * event can succeed — otherwise the user's answer is silently lost.
     */
    public function testFailedProcessingReleasesWebhookEventIdForRedelivery()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

        // transient DB error stand-in: the FIRST answer save throws, later ones succeed
        $failOnce = true;
        getModelName('safety_check_answer')::saving(function () use (&$failOnce) {
            if ($failOnce) {
                $failOnce = false;
                throw new \RuntimeException('forced transient failure');
            }
        });

        $tap = $this->postbackEvent(
            'act=safety&event=' . $event->id . '&st=safe',
            $lineUserId,
            'rt-fail',
            ['webhookEventId' => 'WH-transient-1', 'deliveryContext' => ['isRedelivery' => false]]
        );
        $this->postWebhook(['events' => [$tap]])->assertStatus(500);
        $this->assertEquals('not_answered', array_get($this->freshAnswerRow($row->id)->value, 'answer_status'));

        // LINE redelivers the same webhookEventId; the DB hiccup is gone
        $redelivered = $tap;
        $redelivered['deliveryContext'] = ['isRedelivery' => true];
        $this->postWebhook(['events' => [$redelivered]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('safe', array_get($fresh->value, 'answer_status'), 'The redelivered event must be processed after the first attempt failed.');
    }
}
