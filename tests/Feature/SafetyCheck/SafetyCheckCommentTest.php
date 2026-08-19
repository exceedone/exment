<?php

namespace Exceedone\Exment\Tests\Feature\SafetyCheck;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Services\SafetyCheck\SafetyCheckInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Task 7 - Webhook plain-text messages from an already-linked LINE user are attached as a
 * timestamped comment onto the current open safety_check_answer row for that user, so a
 * follow-up free-text detail ("足を怪我しました" etc.) isn't rejected as an invalid command.
 *
 * Comments are only accepted inside the "comment window": the user must have pressed an
 * answer button first (answer_status != not_answered) and the row's updated_at must be
 * within safety_check_comment_window_minutes (default 60; every button press / comment
 * save refreshes it). Outside the window the text falls back to line.invalid_command.
 *
 * Exercises the real public route admin/line/webhook with a valid signature
 * (same conventions as LineWebhookTest / SafetyCheckPostbackTest).
 */
class SafetyCheckCommentTest extends FeatureTestBase
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

    protected function messageEvent(string $text, string $lineUserId, string $replyToken = 'rt-comment'): array
    {
        return [
            'type' => 'message',
            'replyToken' => $replyToken,
            'source' => ['userId' => $lineUserId],
            'message' => ['type' => 'text', 'text' => $text],
        ];
    }

    /** Answer row as it looks right after the user pressed an answer button (window open). */
    protected function createAnsweredRow($eventId, int $userId, string $status = 'safe')
    {
        return $this->createAnswerRow($eventId, $userId, [
            'answer_status' => $status,
            'answered_at' => now()->format('Y-m-d H:i:s'),
            'channel' => 'line',
        ]);
    }

    /** Backdates the row's updated_at (raw query - saving via Eloquent would refresh it). */
    protected function backdateRowUpdatedAt($rowId, \Carbon\Carbon $to): void
    {
        \DB::table(getDBTableName(CustomTable::getEloquent('safety_check_answer')))
            ->where('id', $rowId)
            ->update(['updated_at' => $to->format('Y-m-d H:i:s')]);
    }

    // -------------------------------------------------- tests

    public function testTextMessageAttachesCommentWithTimestampAndReplies()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnsweredRow($event->id, $userId);

        $this->postWebhook(['events' => [
            $this->messageEvent('足を怪我しました', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $comment = array_get($fresh->value, 'comment');
        $this->assertStringContainsString('足を怪我しました', $comment);
        $this->assertStringContainsString('[', $comment, 'A timestamp prefix must be present.');

        $this->assertEquals(exmtrans('safety.comment_added'), $this->lastReplyText());
    }

    public function testSecondMessageAppendsAsSecondLineInsteadOfOverwriting()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnsweredRow($event->id, $userId);

        $this->postWebhook(['events' => [
            $this->messageEvent('足を怪我しました', $lineUserId),
        ]])->assertStatus(200);
        $this->postWebhook(['events' => [
            $this->messageEvent('水が足りません', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $comment = array_get($fresh->value, 'comment');
        $lines = explode("\n", $comment);

        $this->assertCount(2, $lines, 'The second message must append a second line, not overwrite the first.');
        $this->assertStringContainsString('足を怪我しました', $lines[0]);
        $this->assertStringContainsString('水が足りません', $lines[1]);

        $this->assertEquals(exmtrans('safety.comment_added'), $this->lastReplyText());
    }

    public function testClosedEventLeavesCommentUnchangedAndRepliesInvalidCommand()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent(['event_status' => 'closed']);
        // answered + fresh updated_at: the ONLY thing blocking the comment is the closed event
        $row = $this->createAnsweredRow($event->id, $userId);

        $this->postWebhook(['events' => [
            $this->messageEvent('遅れた報告です', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertNull(array_get($fresh->value, 'comment'), 'No open event means the comment must not change.');

        $this->assertEquals(exmtrans('line.invalid_command'), $this->lastReplyText());
    }

    public function testTextBeforeAnsweringIsNotAttachedAsComment()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnswerRow($event->id, $userId); // not_answered: no button pressed yet

        $this->postWebhook(['events' => [
            $this->messageEvent('まだボタンを押していません', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertNull(array_get($fresh->value, 'comment'), 'Before the user presses an answer button, plain text must not become a comment.');
        $this->assertEquals(exmtrans('line.invalid_command'), $this->lastReplyText());
    }

    public function testTextAfterCommentWindowExpiredIsNotAttachedAsComment()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnsweredRow($event->id, $userId);
        // last interaction 61 minutes ago -> outside the default 60-minute window
        $this->backdateRowUpdatedAt($row->id, now()->subMinutes(61));

        $this->postWebhook(['events' => [
            $this->messageEvent('遅すぎる補足です', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertNull(array_get($fresh->value, 'comment'), 'After the comment window expires, plain text must not become a comment.');
        $this->assertEquals(exmtrans('line.invalid_command'), $this->lastReplyText());
    }

    public function testLinkCommandIsNotSwallowedByCommentHandling()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        // comment window OPEN: if LINK precedence ever broke, the text WOULD become a comment,
        // so the assertions below have teeth
        $row = $this->createAnsweredRow($event->id, $userId);

        $this->postWebhook(['events' => [
            $this->messageEvent('LINK ABC123', $lineUserId),
        ]])->assertStatus(200);

        $reply = $this->lastReplyText();
        $this->assertNotEquals(exmtrans('safety.comment_added'), $reply, 'A LINK command must still go through the linker, not the comment handler.');
        $this->assertEquals(exmtrans('line.link_already_linked'), $reply);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertNull(array_get($fresh->value, 'comment'), 'A LINK command must not be recorded as a comment.');
    }

    /**
     * user2 belongs to role group "user_group" with NO permission on the safety_check_*
     * tables. The comment must still attach: identity is proven by the signed webhook +
     * LineAccountLink chain and the row touched is the user's own.
     * Regression test for CustomValueModelScope forcing `id < 0` on the internal lookups.
     */
    public function testRegularUserWithoutTablePermissionCanComment()
    {
        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER2;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnsweredRow($event->id, $userId);

        $this->postWebhook(['events' => [
            $this->messageEvent('腕を怪我しました', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $comment = array_get($fresh->value, 'comment');
        $this->assertStringContainsString('腕を怪我しました', (string) $comment, 'A user without table permission must still be able to comment on their own row.');

        $this->assertEquals(exmtrans('safety.comment_added'), $this->lastReplyText());
    }

    /**
     * comment_window_minutes emptied in the admin UI is stored as 0, which would make
     * the window permanently closed (every follow-up rejected). It must fall back to
     * the Define default (60) instead.
     */
    public function testEmptyCommentWindowFallsBackToDefault()
    {
        \Exceedone\Exment\Model\System::safety_check_comment_window_minutes(0);
        \Exceedone\Exment\Model\System::clearCache();

        $userId = (int) TestDefine::TESTDATA_USER_LOGINID_USER1;
        $lineUserId = $this->linkUser($userId);
        $event = $this->createEvent();
        $row = $this->createAnsweredRow($event->id, $userId); // answered just now

        $this->postWebhook(['events' => [
            $this->messageEvent('設定が空でも補足です', $lineUserId),
        ]])->assertStatus(200);

        $fresh = $this->freshAnswerRow($row->id);
        $this->assertStringContainsString('設定が空でも補足です', (string) array_get($fresh->value, 'comment'), 'comment_window=0 must fall back to the default window, not permanently reject comments.');
        $this->assertEquals(exmtrans('safety.comment_added'), $this->lastReplyText());
    }
}
