<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

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

    protected function postbackEvent(string $data, string $lineUserId, string $replyToken = 'rt-safety', array $overrides = []): array
    {
        return array_merge([
            'type' => 'postback',
            'replyToken' => $replyToken,
            'source' => ['userId' => $lineUserId],
            'postback' => ['data' => $data],
        ], $overrides);
    }

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

        $helpEvent = $this->postbackEvent(
            'act=safety&event=' . $event->id . '&st=need_help',
            $lineUserId,
            'rt-second',
            ['webhookEventId' => 'WH-help-2', 'deliveryContext' => ['isRedelivery' => false]]
        );
        $this->postWebhook(['events' => [$helpEvent]])->assertStatus(200);
        $this->assertEquals('need_help', array_get($this->freshAnswerRow($row->id)->value, 'answer_status'));

        $redelivered = $safeEvent;
        $redelivered['deliveryContext'] = ['isRedelivery' => true];
        $this->postWebhook(['events' => [$redelivered]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('need_help', array_get($fresh->value, 'answer_status'), 'A redelivered older webhook event must not overwrite the newer answer.');
    }

    public function testFailedProcessingReleasesWebhookEventIdForRedelivery()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId);

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

        $redelivered = $tap;
        $redelivered['deliveryContext'] = ['isRedelivery' => true];
        $this->postWebhook(['events' => [$redelivered]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('safe', array_get($fresh->value, 'answer_status'), 'The redelivered event must be processed after the first attempt failed.');
    }

    public function testRecordAnswerWritesRowWithChannel()
    {
        $event = $this->createEvent();
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $row = $this->createAnswerRow($event->id, $userId);

        $ok = \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::recordAnswer($event->id, $userId, 'safe', 'mail', 'from web');

        $this->assertTrue($ok);
        $fresh = $this->freshAnswerRow($row->id);
        $this->assertEquals('safe', $fresh->getValue('answer_status'));
        $this->assertEquals('mail', $fresh->getValue('channel'));
        $this->assertNotNull($fresh->getValue('answered_at'));
        $this->assertStringContainsString('from web', (string) $fresh->getValue('comment'));
    }

    public function testRecordAnswerReturnsFalseWhenRowMissing()
    {
        $event = $this->createEvent();
        $this->assertFalse(\Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::recordAnswer($event->id, 99999, 'safe', 'mail'));
    }

    public function testCurrentAnswerFindsRow()
    {
        $event = $this->createEvent();
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $this->createAnswerRow($event->id, $userId, ['answer_status' => 'safe']);

        $found = \Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::currentAnswer($event->id, $userId);
        $this->assertNotNull($found);
        $this->assertEquals('safe', $found->getValue('answer_status'));
        $this->assertNull(\Exceedone\Exment\Services\SafetyCheck\SafetyCheckAction::currentAnswer($event->id, 99999));
    }
}
