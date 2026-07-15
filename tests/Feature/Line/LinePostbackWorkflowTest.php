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
 * GĐ3 - E2E postback: người dùng bấm nút trên thẻ Flex -> webhook nhận postback
 * -> LineWorkflowAction thực thi action workflow -> status bản ghi được cập nhật.
 *
 * Đi qua đúng route công khai admin/line/webhook với chữ ký hợp lệ.
 * Không gọi API LINE thật (Http::fake) và không đẩy job notify thật (Bus::fake).
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

    /** POST một postback event với chữ ký hợp lệ. */
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

    /** Tạo 1 bản ghi workflow-enabled ở status 'start'. */
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
        Bus::fake(); // chặn job notify sinh ra sau khi executeAction

        // user1 ở status 'start' có action middle_action (id=1, comment nullable)
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback happy');
        $this->assertEquals('start', $cv->workflow_status_name);

        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });
        $this->assertNotNull($action, 'Fixture: user1 phải có middle_action ở start.');

        // liên kết LINE cho user1
        $lineUserId = 'Upostbackhappy';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $response = $this->postPostback($data, $lineUserId);

        $response->assertStatus(200);
        $this->assertEquals('middle', $this->reload($cv)->workflow_status_name, 'Status phải tiến sang middle sau postback.');
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

        // KHÔNG tạo LineAccountLink cho 'Unosuchlink'
        $this->postPostback($data, 'Unosuchlink')->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'User chưa liên kết không được đổi status.');
    }

    public function testPostbackForActionUserHasNoAuthorityDoesNotChangeStatus()
    {
        Bus::fake();

        // bản ghi tạo bởi user1; user2 ở 'start' KHÔNG có quyền middle_action (deny-by-default)
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback no authority');
        $action = $cv->getWorkflowActions(true, false)
            ->first(function ($a) { return $a->action_name === 'middle_action'; });

        // LINE gắn user2
        $lineUserId = 'Upostbacknoauth';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER2)->markLinked($lineUserId);

        $tableKey = $cv->custom_table->table_name;
        $data = LineFlexBuilder::postbackData($tableKey, $cv->id, $action->id);

        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name, 'User không có quyền action thì status giữ nguyên.');
    }

    public function testInvalidPostbackDataReturns200AndChangesNothing()
    {
        Bus::fake();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
        $cv = $this->createWorkflowValue('postback invalid');
        $lineUserId = 'Upostbackinvalid';
        LineAccountLink::forUser((int) TestDefine::TESTDATA_USER_LOGINID_USER1)->markLinked($lineUserId);

        // thiếu id + action -> LineWorkflowAction trả message lỗi, không đổi status
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

        // action id không tồn tại/không khả dụng -> trả message, không đổi status
        $data = LineFlexBuilder::postbackData($cv->custom_table->table_name, $cv->id, 999999);
        $this->postPostback($data, $lineUserId)->assertStatus(200);

        $this->assertEquals('start', $this->reload($cv)->workflow_status_name);
    }

    // Ghi chú: nhánh comment REQUIRED (action cần nhập ý kiến -> chặn, xử lý trên web)
    // KHÔNG có test tự động. Workflow fixture chỉ có 1 action REQUIRED (end_action) mà
    // không login user nào chạm tới được ở các status khả dụng; còn ép middle_action
    // thành REQUIRED thì side-effect làm mất luôn authority của nó (getWorkflowActions
    // loại action) nên không dựng được cảnh hợp lệ. Guard được kiểm bằng đọc code
    // (LineWorkflowAction::handle + NotifyService::notifyLine, cùng chú thích "FIX 3").
}
