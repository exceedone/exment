<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

/**
 * Phase 3 - E2E postback: the user taps a button on a Flex card -> the webhook
 * receives the postback -> LineWorkflowAction runs the workflow action -> the
 * record's status is updated.
 *
 * Exercises the real public route admin/line/webhook with a valid signature.
 * Does not call the real LINE API (Http::fake) or dispatch the real notify job (Bus::fake).
 */
class LinePostbackWorkflowTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const SECRET = 'postback-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.line.channel_secret' => static::SECRET]);
        config(['exment.line.channel_access_token' => 'postback-test-token']);
        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);
    }

    /** POST a postback event with a valid signature. */
    protected function postPostback(string $data, string $lineUserId)
    {
        $payload = ['events' => [[
            'type' => 'postback',
            'replyToken' => 'rt-postback',
            'source' => ['userId' => $lineUserId],
            'postback' => ['data' => $data],
        ]]];
        $body = json_encode($payload);
        $signature = base64_encode(hash_hmac('sha256', $body, static::SECRET, true));

        return $this->call(
            'POST',
            static::WEBHOOK_URL,
            [],
            [],
            [],
            ['HTTP_X_LINE_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body
        );
    }

    /** Create a workflow-enabled record at the 'start' status. */
    protected function createWorkflowValue(string $text)
    {
        $ct = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $cv = $ct->getValueModel()->setValue(['text' => $text]);
        $cv->save();
        return $ct->getValueModel()->find($cv->id);
    }

    protected function reload($cv)
    {
        return CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)
            ->getValueModel()->find($cv->id);
    }

    // ------------------------------------------------ happy path

    public function testPostbackExecutesWorkflowActionAndAdvancesStatus()
    {
        Bus::fake(); // block the notify job spawned after executeAction

        // user1 at status 'start' has the middle_action action (id=1, comment nullable)
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback happy');
        $this->assertEquals('start', $cv->workflow_status_name);

        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });
        $this->assertNotNull($action, 'Fixture: user1 must have middle_action at start.');

        // link LINE for user1
        $lineUserId = 'Upostbackhappy';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $response = $this->postPostback($data, $lineUserId);

        $response->assertStatus(200);
        $this->assertEquals('middle', $this->reload($cv)->workflow_status_name, 'Status must advance to middle after the postback.');
    }

    // ------------------------------------------------ guards

    public function testPostbackFromUnlinkedLineUserDoesNotChangeStatus()
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback unlinked');
        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        // do NOT create a LineAccountLink for 'Unosuchlink'
        $this->postPostback($data, 'Unosuchlink')->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'An unlinked user must not change the status.');
    }

    public function testPostbackForActionUserHasNoAuthorityDoesNotChangeStatus()
    {
        Bus::fake();

        // record created by user1; user2 at 'start' does NOT have middle_action authority (deny-by-default)
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback no authority');
        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });

        // link LINE to user2
        $lineUserId = 'Upostbacknoauth';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2)->markLinked($lineUserId);

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'A user without action authority leaves the status unchanged.');
    }

    public function testInvalidPostbackDataReturns200AndChangesNothing()
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback invalid');
        $lineUserId = 'Upostbackinvalid';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        // missing id + action -> LineWorkflowAction returns an error message, status unchanged
        $this->postPostback('act=workflow&table=' . $cv->custom_table->table_name, $lineUserId)
            ->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name);
    }

    public function testUnknownActionIdIsRejectedAndStatusUnchanged()
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback unknown action');
        $lineUserId = 'Upostbackunknown';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        // action id does not exist / is unavailable -> returns a message, status unchanged
        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, 999999);
        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name);
    }

    // Note: the comment-REQUIRED branch (action requires a comment -> blocked, handled on the web)
    // has NO automated test. The workflow fixture has only one REQUIRED action (end_action), which
    // no login user can reach at the available statuses; forcing middle_action to REQUIRED has the
    // side effect of stripping its own authority (getWorkflowActions filters the action out), so a
    // valid scenario cannot be built. This guard is verified by reading the code
    // (LineWorkflowAction::handle + NotifyService::notifyLine, alongside the "FIX 3" note).
}
