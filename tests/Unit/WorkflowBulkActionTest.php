<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\WorkflowCommentType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Services\Workflow\WorkflowBulkService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * #1648: run a workflow action on the records ticked in the list.
 *
 * workflow_common_company on custom_value_edit_all: "middle_action" (start -> middle) is run by
 * the user who created the record, "temp_action" (middle -> temp) by dev0-userB, and the next
 * step ("end_action") is done by the users chosen when temp_action runs.
 */
class WorkflowBulkActionTest extends UnitTestBase
{
    use DatabaseTransactions;

    /**
     * @param string $loginId
     * @return void
     */
    protected function beUser($loginId)
    {
        System::clearCache();
        System::clearRequestSession();
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        $this->be(LoginUser::find($loginId));
    }

    protected function table(): CustomTable
    {
        return CustomTable::getEloquent('custom_value_edit_all');
    }

    protected function action(string $name): WorkflowAction
    {
        /** @var WorkflowAction $action */
        $action = WorkflowAction::where('action_name', $name)->first();
        return $action;
    }

    /**
     * The ids of the records the user can run the action on.
     *
     * @return array<int, int>
     */
    protected function runnableIds(string $actionName): array
    {
        $all = $this->table()->getValueQuery()->pluck('id')->all();
        $candidate = (new WorkflowBulkService($this->table(), $all))->getCandidates()->get($this->action($actionName)->id);
        return $candidate['ids'] ?? [];
    }

    /**
     * @param mixed $id
     * @return mixed the status of the record now
     */
    protected function statusOf($id)
    {
        return WorkflowValue::where('morph_type', 'custom_value_edit_all')->where('morph_id', $id)
            ->where('latest_flg', true)->value('workflow_status_to_id');
    }

    public function testCandidatesAreTheActionsTheUserCanRun(): void
    {
        $this->beUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $all = $this->table()->getValueQuery()->pluck('id')->all();
        $candidates = (new WorkflowBulkService($this->table(), $all))->getCandidates();

        // admin can run middle_action on the records admin created, and not temp_action (dev0-userB's)
        $this->assertSame([$this->action('middle_action')->id], $candidates->keys()->all());
        $this->assertGreaterThan(1, count($candidates->first()['ids']));
    }

    public function testRunsTheRecordsThatCanTakeItAndSkipsTheOthers(): void
    {
        $this->beUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $runnable = array_slice($this->runnableIds('middle_action'), 0, 3);
        $this->assertCount(3, $runnable);
        $notRunnable = $this->table()->getValueQuery()->whereNotIn('id', $this->runnableIds('middle_action'))->value('id');
        $this->assertNotNull($notRunnable);

        $service = new WorkflowBulkService($this->table(), array_merge($runnable, [$notRunnable, 99999999]));
        $action = $this->action('middle_action');
        $this->assertNull($service->validate($action, ['comment' => 'bulk']));
        $result = $service->execute($action, ['comment' => 'bulk']);

        $this->assertSame($runnable, $result['executed']);
        $this->assertSame([
            $notRunnable => WorkflowBulkService::SKIP_STATUS_CHANGED,
            99999999 => WorkflowBulkService::SKIP_NOT_FOUND,
        ], $result['skipped']);

        $middle = \DB::table('workflow_statuses')->where('workflow_id', $action->workflow_id)->where('status_name', 'middle')->value('id');
        foreach ($runnable as $id) {
            $this->assertEquals($middle, $this->statusOf($id), "record $id is at middle");
            $this->assertSame('bulk', WorkflowValue::where('morph_type', 'custom_value_edit_all')->where('morph_id', $id)
                ->where('latest_flg', true)->value('comment'));
        }
        $this->assertNull($this->statusOf($notRunnable), 'the record it could not take is not changed');

        $text = WorkflowBulkService::skippedText($result);
        $this->assertStringContainsString('2', $text);
    }

    public function testRunningAgainFindsNothingToDo(): void
    {
        $this->beUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $runnable = array_slice($this->runnableIds('middle_action'), 0, 2);
        $action = $this->action('middle_action');
        (new WorkflowBulkService($this->table(), $runnable))->execute($action, []);

        System::clearRequestSession();
        $again = new WorkflowBulkService($this->table(), $runnable);
        $this->assertNotNull($again->validate($action, []), 'the records moved on, the action is not offered');
        $result = $again->execute($action, []);
        $this->assertSame([], $result['executed']);
        $this->assertCount(2, $result['skipped']);
    }

    public function testRequiredComment(): void
    {
        $this->beUser(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
        $runnable = array_slice($this->runnableIds('middle_action'), 0, 1);
        $action = $this->action('middle_action');
        $action->setOption('comment_type', WorkflowCommentType::REQUIRED);

        $service = new WorkflowBulkService($this->table(), $runnable);
        $this->assertNotNull($service->validate($action, ['comment' => '']));
        $this->assertNull($service->validate($action, ['comment' => 'ok']));
    }

    public function testNextWorkUsersAreChosenWhenTheNextStepAsksForThem(): void
    {
        $this->beUser(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $runnable = $this->runnableIds('temp_action');
        $this->assertNotEmpty($runnable, 'dev0-userB can run temp_action on the record at middle');
        $action = $this->action('temp_action');

        $service = new WorkflowBulkService($this->table(), $runnable);
        $this->assertTrue($service->needsNextWorkUsers($action));
        $this->assertNotNull($service->validate($action, []), 'the next work users have to be chosen');
        $this->assertNotNull($service->validate($action, ['next_work_users' => ['bad value']]));

        $user1 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1)->base_user_id;
        $data = ['next_work_users' => ['user_' . $user1]];
        $this->assertNull($service->validate($action, $data));
        $result = $service->execute($action, $data);
        $this->assertSame($runnable, $result['executed']);

        $value = WorkflowValue::where('morph_type', 'custom_value_edit_all')->where('morph_id', $runnable[0])
            ->where('latest_flg', true)->first();
        $this->assertEquals(
            [$user1],
            \DB::table('workflow_value_authorities')->where('workflow_value_id', $value->id)->where('related_type', 'user')->pluck('related_id')->map(function ($id) {
                return (int)$id;
            })->all()
        );
    }

    public function testOnlyTheRecordsTheUserCanSee(): void
    {
        $this->beUser(TestDefine::TESTDATA_USER_LOGINID_DEV_USERB);
        $table = CustomTable::getEloquent('custom_value_edit');
        $visible = $table->getValueQuery()->pluck('id')->all();
        $hidden = $table->getValueModel()->withoutGlobalScopes()->whereNotIn('id', $visible)->value('id');
        $this->assertNotNull($hidden, 'dev0-userB cannot see some of custom_value_edit');

        $action = WorkflowAction::where('action_name', 'action1')->first();
        $result = (new WorkflowBulkService($table, [$hidden]))->execute($action, []);
        $this->assertSame([], $result['executed']);
        $this->assertSame([$hidden => WorkflowBulkService::SKIP_NOT_FOUND], $result['skipped']);
    }

    public function testIds(): void
    {
        $this->assertSame([3, 1, 2], WorkflowBulkService::parseIds('3,1,,x,2,3,-1'));
        $this->assertCount(WorkflowBulkService::MAX_COUNT, WorkflowBulkService::parseIds(range(1, WorkflowBulkService::MAX_COUNT + 10)));
    }
}
