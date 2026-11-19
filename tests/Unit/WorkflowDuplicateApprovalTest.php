<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\WorkflowNextType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * A step that asks for several people must not be satisfied by one of them.
 *
 * A record can pass the same action more than once - sent back and submitted
 * again, or a step revisited - and each pass leaves another workflow_value
 * behind. WorkflowAction::isActionNext() counted those rows, so one person
 * acting as many times as the step needed people moved it on alone.
 *
 * Nothing here is saved to the action: isActionNext() reads flow_next_type,
 * flow_next_count and the id, and the id has to stay the fixture's own so the
 * rows written below belong to it.
 */
class WorkflowDuplicateApprovalTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * @return array<mixed> [$action, $custom_value]
     */
    protected function prepareStepNeedingTwoPeople()
    {
        System::clearCache();
        System::clearRequestSession();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();

        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));

        $custom_table = CustomTable::getEloquent('workflow1');
        $custom_value = $custom_table->getValueModel()->first();
        $this->assertNotNull($custom_value, 'the workflow1 fixture has to hold at least one record');

        $workflow = Workflow::getWorkflowByTable($custom_table);
        $this->assertNotNull($workflow, 'and workflow1 has to have a workflow on it');

        $action = WorkflowAction::where('workflow_id', $workflow->id)->first();
        $this->assertNotNull($action, 'and that workflow has to have an action');

        $action->flow_next_type = WorkflowNextType::SOME;
        $action->setOption('flow_next_count', 2);

        return [$action, $custom_value];
    }

    /**
     * Write the row an execution of this action leaves behind.
     *
     * @param mixed $custom_value
     * @param mixed $base_user_id who acted
     * @return void
     */
    protected function recordAnExecution(WorkflowAction $action, $custom_value, $base_user_id)
    {
        \DB::table('workflow_values')->insert([
            'suuid' => make_uuid(),
            'workflow_id' => $action->workflow_id,
            'morph_type' => $custom_value->custom_table->table_name,
            'morph_id' => $custom_value->id,
            'workflow_action_id' => $action->id,
            'action_executed_flg' => true,
            'latest_flg' => false,
            'created_user_id' => $base_user_id,
            'updated_user_id' => $base_user_id,
            'created_at' => \Carbon\Carbon::now(),
            'updated_at' => \Carbon\Carbon::now(),
        ]);
    }

    /**
     * @param string $loginId
     * @return mixed the base user id of the user now acting
     */
    protected function beUser($loginId)
    {
        $user = LoginUser::find($loginId);
        System::clearCache();
        System::clearRequestSession();
        $this->be($user);

        return $user->base_user_id;
    }

    /**
     * @return void
     */
    public function testOnePersonActingTwiceDoesNotFillAStepNeedingTwo()
    {
        list($action, $custom_value) = $this->prepareStepNeedingTwoPeople();

        $user1 = $this->beUser(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->recordAnExecution($action, $custom_value, $user1);
        $this->recordAnExecution($action, $custom_value, $user1);

        $this->assertNotTrue(
            $action->isActionNext($custom_value),
            'one person acting twice must not complete a step that asks for two people'
        );
    }

    /**
     * The other half of the rule: counting people must still let the step
     * complete once two different people have acted, however many rows each
     * of them left behind.
     *
     * @return void
     */
    public function testTwoPeopleDoFillIt()
    {
        list($action, $custom_value) = $this->prepareStepNeedingTwoPeople();

        $user2 = $this->beUser(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->recordAnExecution($action, $custom_value, $user2);

        $user1 = $this->beUser(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $this->recordAnExecution($action, $custom_value, $user1);
        $this->recordAnExecution($action, $custom_value, $user1);

        $this->assertTrue(
            $action->isActionNext($custom_value) === true,
            'two people have acted, so the step is complete'
        );
    }
}
