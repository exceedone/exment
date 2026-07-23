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
 * Phase 1 smoke test: a workflow status change sends a LINE text message.
 *
 * Does not call the real LINE API: Bus::fake() intercepts LineSendJob, and we
 * assert the job is dispatched to the correct recipient with the correct text.
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

    /** Read a protected property of the job (LineSendJob exposes no getter). */
    protected function jobProperty(LineSendJob $job, string $name)
    {
        $prop = (new \ReflectionClass($job))->getProperty($name);
        $prop->setAccessible(true);
        return $prop->getValue($job);
    }

    /**
     * A workflow status change dispatches a LINE text job to each linked recipient.
     *
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

        // Create a record, then advance the workflow to the next step (status change)
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->setValue(['text' => 'line smoke test']);
        $custom_value->save();

        $workflow_value = $this->callProtectedMethod($workflow_action, 'forwardWorkflowValue', $custom_value);
        /** @var mixed $custom_value */
        $custom_value = $custom_table->getValueModel()->find($custom_value->id);

        // Users who will be notified at the new status
        $status_to = $workflow_action->getStatusToId($custom_value);
        $users = collect();
        Model\WorkflowStatus::getActionsByFrom($status_to, $workflow, true)
            ->each(function ($action) use (&$users, $custom_value) {
                $users = $users->merge($action->getAuthorityTargets($custom_value, WorkflowGetAuthorityType::NOTIFY));
            });
        $this->assertTrue($users->count() > 0, 'No users found at the next workflow step.');

        // Link LINE for each recipient so notifyLine can resolve their line_user_id
        $expectedLineUserIds = [];
        foreach ($users as $user) {
            $lineUserId = static::LINE_USER_ID_PREFIX . $user->getUserId();
            LineAccountLink::forUser($user->getUserId())->markLinked($lineUserId);
            $expectedLineUserIds[] = $lineUserId;
        }

        // Switch this workflow's notify to the LINE channel (no Flex template selected -> text branch)
        /** @var Notify $notify */
        $notify = Notify::where('notify_trigger', NotifyTrigger::WORKFLOW)
            ->where('notify_view_name', 'workflow_common_company')->first();
        $notify->action_settings = [[
            'notify_action' => NotifyAction::LINE,
            'notify_action_target' => ['created_user', 'work_user'],
        ]];

        $notify->notifyWorkflow($custom_value, $workflow_action, $workflow_value, $status_to);

        // Each recipient must have exactly one LINE push job with non-empty text content
        foreach ($expectedLineUserIds as $lineUserId) {
            Bus::assertDispatchedAfterResponse(LineSendJob::class, function ($job) use ($lineUserId) {
                if ($this->jobProperty($job, 'to') !== $lineUserId) {
                    return false;
                }
                $messages = $this->jobProperty($job, 'messages');
                $this->assertCount(1, $messages);
                $this->assertEquals('text', $messages[0]['type']);
                $this->assertNotEmpty(trim($messages[0]['text']), 'LINE message content is empty.');

                // Text branch: the log context must indicate text and no Flex template
                $context = $this->jobProperty($job, 'context');
                $this->assertEquals('text', $context['message_type']);
                $this->assertNull($context['flex_template_id']);

                return true;
            });
        }
    }

    /**
     * A user not linked to LINE dispatches no job (no error, simply skipped).
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

        // Do NOT create any LineAccountLink
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
