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

    public function testPostbackExecutesWorkflowActionAndAdvancesStatus()
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

    public function testPostbackFromUnlinkedLineUserDoesNotChangeStatus()
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

    public function testPostbackForActionUserHasNoAuthorityDoesNotChangeStatus()
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

    public function testInvalidPostbackDataReturns200AndChangesNothing()
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

    public function testUnknownActionIdIsRejectedAndStatusUnchanged()
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
}
