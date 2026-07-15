<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Enums\NotifyAction;
use Exceedone\Exment\Enums\NotifyTrigger;
use Exceedone\Exment\Enums\WorkflowGetAuthorityType;
use Exceedone\Exment\Jobs\LineSendJob;
use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LineAccountLink;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\Notify;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Tests\TestTrait;
use Illuminate\Support\Facades\Bus;

/**
 * GĐ1 - smoke test: Workflow đổi status -> gửi tin LINE dạng text.
 *
 * Không gọi API LINE thật: Bus::fake() chặn LineSendJob, ta kiểm tra job được
 * đẩy đi với đúng người nhận và đúng nội dung text.
 */
class LineWorkflowNotifyTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    public const LINE_USER_ID_PREFIX = 'Utest';

    protected function init(): void
    {
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1));
    }

    /** Đọc property protected của job (LineSendJob không expose getter). */
    protected function jobProperty(LineSendJob $job, string $name)
    {
        $prop = (new \ReflectionClass($job))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($job);
    }

    /**
     * @return void
     * @throws \ReflectionException
     */
    public function testWorkflowStatusChangeSendsLineText()
    {
        $this->init();
        Bus::fake();

        /** @var Model\Workflow $workflow */
        $workflow = Model\Workflow::where('workflow_view_name', 'workflow_common_company')->first();
        $workflow_action = Model\WorkflowAction::where('action_name', 'middle_action')
            ->where('workflow_id', $workflow->id)->first();
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);

        // tạo bản ghi rồi đẩy workflow sang bước kế tiếp (đổi status)
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->setValue(['text' => 'line smoke test']);
        $custom_value->save();

        $workflow_value = $this->callProtectedMethod($workflow_action, 'forwardWorkflowValue', $custom_value);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->find($custom_value->id);

        // những user sẽ được thông báo ở status mới
        $status_to = $workflow_action->getStatusToId($custom_value);
        $users = collect();
        Model\WorkflowStatus::getActionsByFrom($status_to, $workflow, true)
            ->each(function ($action) use (&$users, $custom_value) {
                $users = $users->merge($action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NOTIFY));
            });
        $this->assertTrue($users->count() > 0, 'Không có user nào ở bước workflow kế tiếp.');

        // liên kết LINE cho từng user nhận, để notifyLine tìm được line_user_id
        $expectedLineUserIds = [];
        foreach ($users as $user) {
            $lineUserId = static::LINE_USER_ID_PREFIX . $user->getUserId();
            LineAccountLink::forUser($user->getUserId())->markLinked($lineUserId);
            $expectedLineUserIds[] = $lineUserId;
        }

        // chuyển notify của workflow này sang kênh LINE (không chọn Flex template -> nhánh text)
        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::WORKFLOW)
            ->where('notify_view_name', 'workflow_common_company')->first();
        $notify->action_settings = [[
            'notify_action' => NotifyAction::LINE,
            'notify_action_target' => ['created_user', 'work_user'],
        ]];

        $notify->notifyWorkflow($custom_value, $workflow_action, $workflow_value, $status_to);

        // mỗi user nhận phải có đúng 1 job push LINE, nội dung là text và không rỗng
        foreach ($expectedLineUserIds as $lineUserId) {
            Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use ($lineUserId) {
                if ($this->jobProperty($job, 'to') !== $lineUserId) {
                    return false;
                }
                $messages = $this->jobProperty($job, 'messages');
                $this->assertCount(1, $messages);
                $this->assertEquals('text', $messages[0]['type']);
                $this->assertNotEmpty(trim($messages[0]['text']), 'Nội dung tin LINE rỗng.');

                // nhánh text: context ghi log phải nói rõ là text và không có Flex template
                $context = $this->jobProperty($job, 'context');
                $this->assertEquals('text', $context['message_type']);
                $this->assertNull($context['flex_template_id']);

                return true;
            });
        }
    }

    /**
     * User chưa liên kết LINE -> không đẩy job nào (không nổ, chỉ bỏ qua).
     *
     * @return void
     * @throws \ReflectionException
     */
    public function testWorkflowStatusChangeSkipsUnlinkedUser()
    {
        $this->init();
        Bus::fake();

        /** @var Model\Workflow $workflow */
        $workflow = Model\Workflow::where('workflow_view_name', 'workflow_common_company')->first();
        $workflow_action = Model\WorkflowAction::where('action_name', 'middle_action')
            ->where('workflow_id', $workflow->id)->first();
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);

        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->setValue(['text' => 'line smoke test unlinked']);
        $custom_value->save();

        $workflow_value = $this->callProtectedMethod($workflow_action, 'forwardWorkflowValue', $custom_value);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->find($custom_value->id);
        $status_to = $workflow_action->getStatusToId($custom_value);

        // KHÔNG tạo LineAccountLink nào
        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::WORKFLOW)
            ->where('notify_view_name', 'workflow_common_company')->first();
        $notify->action_settings = [[
            'notify_action' => NotifyAction::LINE,
            'notify_action_target' => ['created_user', 'work_user'],
        ]];

        $notify->notifyWorkflow($custom_value, $workflow_action, $workflow_value, $status_to);

        Bus::assertNotDispatchedAfterResponse(LineSendJob::class);
    }
}
