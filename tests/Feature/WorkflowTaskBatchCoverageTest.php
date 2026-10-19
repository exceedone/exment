<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\Workflow\WorkflowTaskService;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The batch buttons of the un-actioned workflow task list (admin/workflow_task): mark the ticked
 * tasks read, mark all read / unread, take tasks off the list and put them back.
 *
 * The task is made the way the product makes one: workflow_common_company is in use on
 * custom_value_edit_all and its start action goes to the record's creator, so a record the
 * admin adds waits at the start as the admin's task.
 */
class WorkflowTaskBatchCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testRowCheckMarksTheTickedTaskRead(): void
    {
        $key = $this->newTaskKey();

        $this->post(admin_url('workflow_task/rowCheck'), ['keys' => $key])
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('workflow_task.message.check_succeeded')]);
        $this->assertContains($key, (new WorkflowTaskService())->seenKeys());

        // read already: nothing left to mark
        $this->post(admin_url('workflow_task/rowCheck'), ['keys' => $key])
            ->assertStatus(400)
            ->assertJson(['result' => false, 'toastr' => exmtrans('workflow_task.message.check_notfound')]);
    }

    public function testReadAllAndUnreadAll(): void
    {
        $key = $this->newTaskKey();
        $ajax = ['X-Requested-With' => 'XMLHttpRequest'];

        $this->withHeaders($ajax)->post(admin_url('workflow_task/readAll'))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('workflow_task.message.mark_all_seen_succeeded')]);
        $this->assertSame(0, (new WorkflowTaskService())->countUnseen());
        $this->assertContains($key, (new WorkflowTaskService())->seenKeys());

        $this->withHeaders($ajax)->post(admin_url('workflow_task/unreadAll'))
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('workflow_task.message.mark_all_unseen_succeeded')]);
        $this->assertNotContains($key, (new WorkflowTaskService())->seenKeys());
        $this->assertGreaterThan(0, (new WorkflowTaskService())->countUnseen());

        // a plain form post (no javascript) is sent back to the list
        $this->flushHeaders();
        $this->post(admin_url('workflow_task/readAll'))->assertRedirect(admin_url('workflow_task'));
        $this->assertSame(0, (new WorkflowTaskService())->countUnseen());
    }

    public function testRowDeleteTakesTheTaskOffTheListAndRowRestorePutsItBack(): void
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_value = $this->newRecord();
        $key = WorkflowTaskService::taskKey($custom_table->id, $custom_value->id);
        $this->assertTrue((new WorkflowTaskService())->getTasks()->contains('task_key', $key), 'the new record must be the admin\'s task');

        $this->post(admin_url('workflow_task/rowDelete'), ['keys' => $key])
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('workflow_task.message.delete_succeeded')]);
        $this->assertFalse((new WorkflowTaskService())->getTasks()->contains('task_key', $key), 'a deleted task must leave the list');
        // only the user's own mark is written: the record itself stays
        $this->assertNotNull($custom_table->getValueModel($custom_value->id));

        $this->post(admin_url('workflow_task/rowRestore'), ['keys' => $key])
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => exmtrans('workflow_task.message.restore_succeeded', 1)]);
        $this->assertTrue((new WorkflowTaskService())->getTasks()->contains('task_key', $key), 'a restored task must be listed again');

        // back already: nothing to restore
        $this->post(admin_url('workflow_task/rowRestore'), ['keys' => $key])
            ->assertStatus(400)
            ->assertJson(['result' => false, 'toastr' => exmtrans('workflow_task.message.restore_notfound')]);
    }

    protected function newRecord(): CustomValue
    {
        $custom_value = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->getValueModel();
        $custom_value->setValue('text', 'claude workflow task ' . short_uuid());
        $custom_value->save();

        return $custom_value;
    }

    /**
     * The task key of a new record that waits at the start for the admin.
     */
    protected function newTaskKey(): string
    {
        $custom_value = $this->newRecord();
        $key = WorkflowTaskService::taskKey(CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->id, $custom_value->id);
        $this->assertTrue((new WorkflowTaskService())->getTasks()->contains('task_key', $key), 'the new record must be the admin\'s task');

        return $key;
    }
}
