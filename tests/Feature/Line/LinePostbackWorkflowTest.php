<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\NotifyTarget;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Services\Line\LineFlexBuilder;
use Exceedone\Exment\Services\Line\LineMessagingClient;
use Exceedone\Exment\Services\NotifyService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

class LinePostbackWorkflowTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const SECRET = 'postback-test-secret';
    public const WEBHOOK_URL = 'admin/line/webhook';

    /** @var array<int, RequestInterface> requests the LINE client sent */
    protected $lineRequests = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        config(['exment.line.channel_secret' => static::SECRET]);
        config(['exment.line.channel_access_token' => 'postback-test-token']);
        Http::fake(['api.line.me/*' => Http::response('{}', 200)]);

        $this->lineRequests = [];
        $stack = HandlerStack::create(function (RequestInterface $request) {
            $this->lineRequests[] = $request;
            return Create::promiseFor(new GuzzleResponse(200, [], '{}'));
        });
        $guzzle = new Client(['handler' => $stack, 'base_uri' => 'https://api.line.me']);
        $this->app->bind(LineMessagingClient::class, function () use ($guzzle) {
            return new LineMessagingClient(null, static::SECRET, $guzzle);
        });
    }

    protected function lastReplyText(): ?string
    {
        $text = null;
        foreach ($this->lineRequests as $request) {
            if ($request->getUri()->getPath() === '/v2/bot/message/reply') {
                $body = json_decode((string) $request->getBody(), true);
                $text = $body['messages'][0]['text'] ?? null;
            }
        }
        return $text;
    }

    /**
     * @param \Exceedone\Exment\Model\CustomValue $cv
     */
    protected function findAction($cv, string $actionName): WorkflowAction
    {
        $action = $cv->getWorkflowActions(false, false)
            ->first(function ($a) use ($actionName) { return $a->action_name === $actionName; });
        $this->assertNotNull($action, "Fixture: {$actionName} must exist on the record's workflow.");
        return $action;
    }

    /**
     * @param \Exceedone\Exment\Model\CustomValue $cv
     */
    protected function executedCount($cv, WorkflowAction $action): int
    {
        return WorkflowValue::where('morph_type', $cv->custom_table->table_name)
            ->where('morph_id', $cv->id)
            ->where('workflow_action_id', $action->id)
            ->where('action_executed_flg', true)
            ->count();
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    protected function postPostback(string $data, string $lineUserId)
    {
        $payload = ['events' => [[
            'type' => 'postback',
            'replyToken' => 'rt-postback',
            'source' => ['userId' => $lineUserId],
            'postback' => ['data' => $data],
        ]]];
        $body = (string) json_encode($payload);
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

    /**
     * @return \Exceedone\Exment\Model\CustomValue|null
     */
    protected function createWorkflowValue(string $text)
    {
        $ct = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $cv = $ct->getValueModel()->setValue(['text' => $text]);
        $cv->save();
        /** @var \Exceedone\Exment\Model\CustomValue|null $saved */
        $saved = $ct->getValueModel()->find($cv->id);
        return $saved;
    }

    /**
     * @param \Exceedone\Exment\Model\CustomValue $cv
     * @return \Exceedone\Exment\Model\CustomValue|null
     */
    protected function reload($cv)
    {
        /** @var \Exceedone\Exment\Model\CustomValue|null $fresh */
        $fresh = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)
            ->getValueModel()->find($cv->id);
        return $fresh;
    }

    public function testPostbackExecutesWorkflowActionAndAdvancesStatus(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback happy');
        $this->assertEquals('start', $cv->workflow_status_name);

        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });
        $this->assertNotNull($action, 'Fixture: user1 must have middle_action at start.');

        $lineUserId = 'Upostbackhappy';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $response = $this->postPostback($data, $lineUserId);

        $response->assertStatus(200);
        $this->assertEquals('middle', $this->reload($cv)->workflow_status_name, 'Status must advance to middle after the postback.');
    }

    public function testPostbackFromUnlinkedLineUserDoesNotChangeStatus(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback unlinked');
        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $this->postPostback($data, 'Unosuchlink')->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'An unlinked user must not change the status.');
    }

    public function testPostbackFromDeletedUserDoesNotChangeStatus(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback deleted user');
        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });
        $this->assertNotNull($action, 'Fixture: user1 must have middle_action at start.');

        $lineUserId = 'Upostbackdeleted';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        getModelName(SystemTableName::USER)::find(TestDefine::TESTDATA_USER_LOGINID_USER1)->delete();

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'The LINE account of a deleted user must not act for them.');
        $this->assertEquals(exmtrans('line.account_not_linked'), $this->lastReplyText());
    }

    public function testPostbackForActionUserHasNoAuthorityDoesNotChangeStatus(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback no authority');
        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });

        $lineUserId = 'Upostbacknoauth';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2)->markLinked($lineUserId);

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'A user without action authority leaves the status unchanged.');
    }

    public function testInvalidPostbackDataReturns200AndChangesNothing(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback invalid');
        $lineUserId = 'Upostbackinvalid';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        $this->postPostback('act=workflow&table=' . $cv->custom_table->table_name, $lineUserId)
            ->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name);
    }

    public function testUnknownActionIdIsRejectedAndStatusUnchanged(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback unknown action');
        $lineUserId = 'Upostbackunknown';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, 999999);
        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name);
    }

    public function testPostbackIsRefusedWhenNextStepNeedsAssigneeSelection(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback needs selection');
        $this->findAction($cv, 'middle_action')->executeAction($cv, []);
        $cv = $this->reload($cv);
        $this->assertEquals('middle', $cv->workflow_status_name);
        $tempAction = $this->findAction($cv, 'temp_action');
        $valueCount = WorkflowValue::where('morph_type', $cv->custom_table->table_name)->where('morph_id', $cv->id)->count();

        $lineUserId = 'Upostbackselect';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_DEV_USERB)->markLinked($lineUserId);
        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $tempAction->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('middle', $this->reload($cv)->workflow_status_name, 'The record must not move to a status nobody can act on.');
        $this->assertEquals($valueCount, WorkflowValue::where('morph_type', $cv->custom_table->table_name)->where('morph_id', $cv->id)->count());
        $this->assertEquals(exmtrans('line.action_need_next_user'), $this->lastReplyText());
    }

    /**
     * middle_action needs 2 approvals. The same user tapping again must not count as a second approver.
     */
    public function testRepeatedPostbackBySameUserCountsAsOneApproval(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback repeated tap');
        $action = $this->findAction($cv, 'middle_action');
        $options = $action->options;
        $options['flow_next_count'] = '2';
        \DB::table('workflow_actions')->where('id', $action->id)->update(['options' => json_encode($options)]);
        System::clearCache();

        $lineUserId = 'Upostbackrepeat';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);
        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);
        $this->postPostback($data, $lineUserId)->assertStatus(200);
        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'One user must not complete a 2-approver step alone.');
        $this->assertEquals(1, $this->executedCount($cv, $action));
        $this->assertEquals(exmtrans('line.action_unavailable'), $this->lastReplyText());
    }

    public function testPostbackIsRefusedWhenNobodyCanHandleNextStep(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback no next user');
        $action = $this->findAction($cv, 'middle_action');
        $tempAction = WorkflowAction::where('workflow_id', $action->workflow_id)->where('action_name', 'temp_action')->first();
        $this->assertNotNull($tempAction, 'Fixture: temp_action must follow middle_action.');
        WorkflowAuthority::where('workflow_action_id', $tempAction->id)->delete();
        System::clearCache();

        $lineUserId = 'Upostbacknonext';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);
        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'The record must not move to a status nobody can act on.');
        $this->assertEquals(0, $this->executedCount($cv, $action));
        $this->assertEquals(exmtrans('workflow.message.nextuser_not_found'), $this->lastReplyText());
    }

    /**
     * Postback data of the buttons in the Flex message LINE would send to $userId for $cv.
     *
     * @param \Exceedone\Exment\Model\CustomValue $cv
     * @return array<int, string>
     */
    protected function flexPostbackData($cv, int $userId): array
    {
        $tmpl = CustomTable::getEloquent('line_flex_template')->getValueModel();
        $tmpl->setValue(['template_name' => 'postback-buttons', 'title' => 'buttons', 'body_items' => '']);
        $tmpl->save();

        Bus::fake();
        NotifyService::notifyLine([
            'user' => NotifyTarget::getModelAsUser(getModelName(SystemTableName::USER)::find($userId)),
            'custom_value' => $cv,
            'subject' => 'subject',
            'body' => 'body',
            'flex_template_id' => $tmpl->id,
        ]);

        $data = [];
        Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use (&$data) {
            $prop = (new \ReflectionClass($job))->getProperty('messages');
            $prop->setAccessible(true);
            $messages = $prop->getValue($job);
            array_walk_recursive($messages, function ($value, $key) use (&$data) {
                if ($key === 'data') {
                    $data[] = $value;
                }
            });
            return true;
        });
        return $data;
    }

    public function testFlexOffersButtonForActionThatCanRunFromLine(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('flex plain button');
        $action = $this->findAction($cv, 'middle_action');
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked('Uflexplain');

        $data = $this->flexPostbackData($cv, (int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $this->assertContains(LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $action->id), $data);
    }

    public function testFlexOmitsButtonWhenNextStepNeedsAssigneeSelection(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('flex needs selection');
        $this->findAction($cv, 'middle_action')->executeAction($cv, []);
        $cv = $this->reload($cv);
        $tempAction = $this->findAction($cv, 'temp_action');
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_DEV_USERB)->markLinked('Uflexselect');

        $data = $this->flexPostbackData($cv, (int) TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);

        $this->assertNotContains(LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $tempAction->id), $data);
    }

    public function testFlexOmitsButtonForApproverWhoAlreadyActed(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('flex already acted');
        $action = $this->findAction($cv, 'middle_action');
        $options = $action->options;
        $options['flow_next_count'] = '2';
        \DB::table('workflow_actions')->where('id', $action->id)->update(['options' => json_encode($options)]);
        System::clearCache();
        $this->findAction($cv, 'middle_action')->executeAction($cv, []);
        $cv = $this->reload($cv);
        $this->assertEquals('start', $cv->workflow_status_name, 'Fixture: one approval of two keeps the status.');
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked('Uflexacted');

        $data = $this->flexPostbackData($cv, (int) TestDefine::TESTDATA_USER_LOGINID_USER1);

        $this->assertNotContains(LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $action->id), $data);
    }

    public function testPostbackReplyReportsDoneOnHappyPath(): void
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback done reply');
        $action = $this->findAction($cv, 'middle_action');

        $lineUserId = 'Upostbackdone';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);
        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('middle', $this->reload($cv)->workflow_status_name);
        $this->assertEquals(exmtrans('line.action_done', 'middle_action'), $this->lastReplyText());
    }
}
