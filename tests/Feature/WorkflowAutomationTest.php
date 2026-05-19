<?php

namespace Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowTable;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowAuthority;
use Exceedone\Exment\Model\WorkflowConditionHeader;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Enums\ConditionTypeDetail;
use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Schema;

class WorkflowAutomationTest extends TestCase
{
    protected $submitterLoginUser;
    protected $approverLoginUser;
    protected $workflow;
    protected $customTable;
    protected $tableName;
    protected $submitterCvId;
    protected $approverCvId;

    protected function setUp(): void
    {
        parent::setUp();

        // Temp Admin login to initialize Data
        $admin = LoginUser::find(1);
        if (!$admin) {
            $this->markTestSkipped('Admin User not found to run setup.');
        }
        $this->actingAs($admin, 'admin');
        \Exment::setGuard('admin');

        $timestamp = date('YmdHis');

        // ---------------------------------------------------------
        // Setup: Initialize Test Users via user CustomTable (Find if exists, or create new)
        // ---------------------------------------------------------
        $userTable = CustomTable::getEloquent('user');
        
        // Find existing Submitter
        $this->submitterLoginUser = LoginUser::all()->first(function($user) {
            return $user->base_user && $user->base_user->getValue('user_code') === 'TEST_SUBMITTER';
        });
        if ($this->submitterLoginUser) {
            $this->submitterCvId = $this->submitterLoginUser->base_user_id;
        } else {
            $submitterCv = $userTable->getValueModel()->newInstance();
            $submitterCv->setValue('user_code', 'TEST_SUBMITTER');
            $submitterCv->setValue('name', 'Test Submitter');
            $submitterCv->setValue('email', 'submitter@example.com');
            $submitterCv->setValue('password', 'password123');
            $submitterCv->save();
            $this->submitterCvId = $submitterCv->id;
            $this->submitterLoginUser = LoginUser::create(['base_user_id' => $this->submitterCvId, 'password' => bcrypt('password123')]);
        }

        // Find existing Approver
        $this->approverLoginUser = LoginUser::all()->first(function($user) {
            return $user->base_user && $user->base_user->getValue('user_code') === 'TEST_APPROVER';
        });
        if ($this->approverLoginUser) {
            $this->approverCvId = $this->approverLoginUser->base_user_id;
        } else {
            $approverCv = $userTable->getValueModel()->newInstance();
            $approverCv->setValue('user_code', 'TEST_APPROVER');
            $approverCv->setValue('name', 'Test Approver');
            $approverCv->setValue('email', 'approver@example.com');
            $approverCv->setValue('password', 'password123');
            $approverCv->save();
            $this->approverCvId = $approverCv->id;
            $this->approverLoginUser = LoginUser::create(['base_user_id' => $this->approverCvId, 'password' => bcrypt('password123')]);
        }

        // ---------------------------------------------------------
        // Setup: Initialize/Find Table and Test Columns
        // ---------------------------------------------------------
        $reusedTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if ($reusedTable) {
            $this->customTable = $reusedTable;
            $this->tableName = $reusedTable->table_name;
            
            // Check if column 'test_name' exists, create if not
            $hasCol = CustomColumn::where('custom_table_id', $this->customTable->id)
                ->where('column_name', 'test_name')
                ->exists();
            if (!$hasCol) {
                CustomColumn::create([
                    'custom_table_id' => $this->customTable->id,
                    'column_name' => 'test_name',
                    'column_view_name' => 'Test Name',
                    'column_type' => 'text',
                    'order' => 10,
                ]);
                System::clearCache();
            }

            // Check if column 'test_price' exists, create if not
            $hasPriceCol = CustomColumn::where('custom_table_id', $this->customTable->id)
                ->where('column_name', 'test_price')
                ->exists();
            if (!$hasPriceCol) {
                CustomColumn::create([
                    'custom_table_id' => $this->customTable->id,
                    'column_name' => 'test_price',
                    'column_view_name' => 'Test Price',
                    'column_type' => 'integer',
                    'order' => 20,
                ]);
                System::clearCache();
            }
        } else {
            $this->tableName = 'uni_table_' . $timestamp;
            $this->customTable = CustomTable::create([
                'table_name' => $this->tableName,
                'table_view_name' => 'Workflow Test Table ' . $timestamp,
                'order' => 10,
                'options' => [
                    'use_label_id_flg' => '0',
                    'all_user_editable_flg' => '1',
                    'all_user_viewable_flg' => '1',
                    'all_user_accessable_flg' => '1',
                ],
            ]);
            System::clearCache();

            CustomColumn::create([
                'custom_table_id' => $this->customTable->id,
                'column_name' => 'test_name',
                'column_view_name' => 'Test Name',
                'column_type' => 'text',
                'order' => 10,
            ]);
            System::clearCache();

            CustomColumn::create([
                'custom_table_id' => $this->customTable->id,
                'column_name' => 'test_price',
                'column_view_name' => 'Test Price',
                'column_type' => 'integer',
                'order' => 20,
            ]);
            System::clearCache();
        }

        // Deactivate old workflows for this table to prevent conflicts
        WorkflowTable::where('custom_table_id', $this->customTable->id)->update(['active_flg' => 0]);
        System::clearCache();

        // ---------------------------------------------------------
        // Setup: Initialize new Workflow with Timestamp
        // ---------------------------------------------------------
        $this->workflow = Workflow::create([
            'workflow_view_name' => 'Test Workflow ' . $timestamp,
            'workflow_type' => WorkflowType::TABLE,
            'start_status_name' => 'New',
            'setting_completed_flg' => 1,
        ]);

        WorkflowTable::create([
            'workflow_id' => $this->workflow->id,
            'custom_table_id' => $this->customTable->id,
            'active_flg' => 1,
        ]);

        $statusPending = WorkflowStatus::create([
            'workflow_id' => $this->workflow->id,
            'status_name' => 'Pending Approval',
            'order' => 10,
        ]);

        $statusApproved = WorkflowStatus::create([
            'workflow_id' => $this->workflow->id,
            'status_name' => 'Approved',
            'order' => 20,
        ]);

        // Action 1: Submit (New -> Pending Approval) - Submitter is authorized
        $actionSubmit = WorkflowAction::create([
            'workflow_id' => $this->workflow->id,
            'action_name' => 'Submit',
            'status_from' => Define::WORKFLOW_START_KEYNAME,
            'options' => [
                'work_target_type' => WorkflowWorkTargetType::FIX,
            ],
        ]);
        \Illuminate\Support\Facades\DB::table('workflow_authorities')->insert([
            'workflow_action_id' => $actionSubmit->id,
            'related_id' => $this->submitterCvId,
            'related_type' => ConditionTypeDetail::USER()->lowerKey(),
        ]);
        WorkflowConditionHeader::create([
            'workflow_action_id' => $actionSubmit->id,
            'status_to' => $statusPending->id,
            'enabled_flg' => 1,
        ]);

        // Action 2: Approve (Pending Approval -> Approved) - Approver is authorized
        $actionApprove = WorkflowAction::create([
            'workflow_id' => $this->workflow->id,
            'action_name' => 'Approve',
            'status_from' => $statusPending->id,
            'options' => [
                'work_target_type' => WorkflowWorkTargetType::FIX,
            ],
        ]);
        \Illuminate\Support\Facades\DB::table('workflow_authorities')->insert([
            'workflow_action_id' => $actionApprove->id,
            'related_id' => $this->approverCvId,
            'related_type' => ConditionTypeDetail::USER()->lowerKey(),
        ]);
        WorkflowConditionHeader::create([
            'workflow_action_id' => $actionApprove->id,
            'status_to' => $statusApproved->id,
            'enabled_flg' => 1,
        ]);

        // Status: Rejected
        $statusRejected = WorkflowStatus::create([
            'workflow_id' => $this->workflow->id,
            'status_name' => 'Rejected',
            'order' => 30,
        ]);

        // Action 3: Reject (Pending Approval -> Rejected) - Approver is authorized
        $actionReject = WorkflowAction::create([
            'workflow_id' => $this->workflow->id,
            'action_name' => 'Reject',
            'status_from' => $statusPending->id,
            'options' => [
                'work_target_type' => WorkflowWorkTargetType::FIX,
            ],
        ]);
        \Illuminate\Support\Facades\DB::table('workflow_authorities')->insert([
            'workflow_action_id' => $actionReject->id,
            'related_id' => $this->approverCvId,
            'related_type' => ConditionTypeDetail::USER()->lowerKey(),
        ]);
        WorkflowConditionHeader::create([
            'workflow_action_id' => $actionReject->id,
            'status_to' => $statusRejected->id,
            'enabled_flg' => 1,
        ]);

        System::clearCache();
    }

    protected function teardownTestData()
    {
        // Do not delete test data so subsequent tests can reuse it
    }

    protected function getUnusedExistingRecord($customTable)
    {
        $records = $customTable->getValueModel()->newQuery()->get();
        foreach ($records as $record) {
            $hasWf = WorkflowValue::where('morph_type', $customTable->table_name)
                ->where('morph_id', $record->id)
                ->exists();
            if (!$hasWf) {
                return $record;
            }
        }
        return null;
    }

    public function tearDown(): void
    {
        parent::tearDown();
    }

    public function test_business_workflow()
    {
        // ---------------------------------------------------------
        // Step 1 - Trigger Workflow (Create Data & Submit)
        // ---------------------------------------------------------
        $this->actingAs($this->submitterLoginUser, 'admin');

        // Reuse existing record if available, otherwise create a new one
        $customTable = CustomTable::getEloquent($this->tableName);
        $model = $this->getUnusedExistingRecord($customTable);
        if (!$model) {
            $model = $customTable->getValueModel();
            $model->setValue('test_name', 'Approval Request ' . date('YmdHis'));
            $model->setValue('test_price', 150000);
            $model->save();
        }
        $recordId = $model->id;

        $this->assertNotNull($recordId, 'Data record not created or found.');

        $actionSubmit = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Submit')
            ->first();

        // Send request to execute "Submit" action
        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionSubmit->id,
            'comment' => 'Please approve this request.',
        ]);

        $response->assertStatus(200);
        $resJson = $response->json();
        $this->assertTrue($resJson['result'] ?? false, 'Submit action execution failed: ' . json_encode($resJson));

        // Assert Workflow state is "Pending Approval"
        System::clearCache();
        $statusPending = WorkflowStatus::where('workflow_id', $this->workflow->id)->where('status_name', 'Pending Approval')->first();
        
        $workflowValue = WorkflowValue::where('morph_type', $this->tableName)
            ->where('morph_id', $recordId)
            ->where('latest_flg', 1)
            ->first();

        $this->assertNotNull($workflowValue, 'No WorkflowValue record found.');
        $this->assertEquals($statusPending->id, $workflowValue->workflow_status_to_id, 'Status is not Pending Approval.');

        // ---------------------------------------------------------
        // Step 2 - Approve Action
        // ---------------------------------------------------------
        $this->actingAs($this->approverLoginUser, 'admin');

        $actionApprove = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Approve')
            ->first();

        // Send request to execute "Approve" action
        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionApprove->id,
            'comment' => 'Approved. Looks good!',
        ]);

        $response->assertStatus(200);
        $resJson = $response->json();
        $this->assertTrue($resJson['result'] ?? false, 'Approve action execution failed: ' . json_encode($resJson));

        // ---------------------------------------------------------
        // Step 3 - Verify State (Approved)
        // ---------------------------------------------------------
        System::clearCache();
        $statusApproved = WorkflowStatus::where('workflow_id', $this->workflow->id)->where('status_name', 'Approved')->first();
        
        $finalWorkflowValue = WorkflowValue::where('morph_type', $this->tableName)
            ->where('morph_id', $recordId)
            ->where('latest_flg', 1)
            ->first();

        $this->assertEquals($statusApproved->id, $finalWorkflowValue->workflow_status_to_id, 'Final status is not Approved.');
        $this->assertEquals('Approved. Looks good!', $finalWorkflowValue->comment, 'Approver comment was not saved.');
    }

    /**
     * Test: Reject Workflow - Reject path
     */
    public function test_workflow_reject_path()
    {
        // ---------------------------------------------------------
        // Step 1 - Submitter creates Data & Submits
        // ---------------------------------------------------------
        $this->actingAs($this->submitterLoginUser, 'admin');

        $customTable = CustomTable::getEloquent($this->tableName);
        $model = $this->getUnusedExistingRecord($customTable);
        if (!$model) {
            $model = $customTable->getValueModel();
            $model->setValue('test_name', 'Reject Request ' . date('YmdHis'));
            $model->setValue('test_price', 999000);
            $model->save();
        }
        $recordId = $model->id;

        $this->assertNotNull($recordId, 'Data record not created or found.');

        $actionSubmit = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Submit')
            ->first();

        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionSubmit->id,
            'comment' => 'Please review this.',
        ]);

        $response->assertStatus(200);
        $resJson = $response->json();
        $this->assertTrue($resJson['result'] ?? false, 'Submit action failed: ' . json_encode($resJson));

        // Verify Pending Approval status
        System::clearCache();
        $statusPending = WorkflowStatus::where('workflow_id', $this->workflow->id)
            ->where('status_name', 'Pending Approval')
            ->first();

        $workflowValue = WorkflowValue::where('morph_type', $this->tableName)
            ->where('morph_id', $recordId)
            ->where('latest_flg', 1)
            ->first();
        $this->assertEquals($statusPending->id, $workflowValue->workflow_status_to_id);

        // ---------------------------------------------------------
        // Step 2 - Approver executes Reject
        // ---------------------------------------------------------
        $this->actingAs($this->approverLoginUser, 'admin');

        $actionReject = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Reject')
            ->first();

        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionReject->id,
            'comment' => 'Rejected. Price too high.',
        ]);

        $response->assertStatus(200);
        $resJson = $response->json();
        $this->assertTrue($resJson['result'] ?? false, 'Reject action failed: ' . json_encode($resJson));

        // ---------------------------------------------------------
        // Step 3 - Verify Rejected status
        // ---------------------------------------------------------
        System::clearCache();
        $statusRejected = WorkflowStatus::where('workflow_id', $this->workflow->id)
            ->where('status_name', 'Rejected')
            ->first();

        $finalWorkflowValue = WorkflowValue::where('morph_type', $this->tableName)
            ->where('morph_id', $recordId)
            ->where('latest_flg', 1)
            ->first();

        $this->assertNotNull($finalWorkflowValue, 'No WorkflowValue found after Reject.');
        $this->assertEquals($statusRejected->id, $finalWorkflowValue->workflow_status_to_id,
            'Final status is not Rejected.');
        $this->assertEquals('Rejected. Price too high.', $finalWorkflowValue->comment,
            'Reject comment was not saved.');
    }

    /**
     * Test: Unauthorized user executing action should fail
     */
    public function test_workflow_unauthorized_action()
    {
        // ---------------------------------------------------------
        // Step 1 - Submitter creates Data & Submits
        // ---------------------------------------------------------
        $this->actingAs($this->submitterLoginUser, 'admin');

        $customTable = CustomTable::getEloquent($this->tableName);
        $model = $this->getUnusedExistingRecord($customTable);
        if (!$model) {
            $model = $customTable->getValueModel();
            $model->setValue('test_name', 'Unauthorized Test ' . date('YmdHis'));
            $model->setValue('test_price', 300000);
            $model->save();
        }
        $recordId = $model->id;

        $this->assertNotNull($recordId, 'Data record not created or found.');

        $actionSubmit = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Submit')
            ->first();

        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionSubmit->id,
            'comment' => 'Submit for test.',
        ]);
        $response->assertStatus(200);

        System::clearCache();

        // ---------------------------------------------------------
        // Step 2 - Submitter attempts to Approve (unauthorized)
        // Submitter only has Submit permission, not Approve
        // ---------------------------------------------------------
        $this->actingAs($this->submitterLoginUser, 'admin');

        $actionApprove = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Approve')
            ->first();

        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionApprove->id,
            'comment' => 'Should not be allowed.',
        ]);

        // Expect failure (result = false or status != 200)
        if ($response->status() === 200) {
            $resJson = $response->json();
            $this->assertFalse($resJson['result'] ?? true,
                'Submitter should not have Approve permission but the action succeeded.');
        } else {
            $this->assertTrue(
                in_array($response->status(), [403, 422, 400]),
                'Expected 403/422/400 but got: ' . $response->status()
            );
        }

        // ---------------------------------------------------------
        // Step 3 - Verify status remains Pending Approval (unchanged)
        // ---------------------------------------------------------
        System::clearCache();
        $statusPending = WorkflowStatus::where('workflow_id', $this->workflow->id)
            ->where('status_name', 'Pending Approval')
            ->first();

        $workflowValue = WorkflowValue::where('morph_type', $this->tableName)
            ->where('morph_id', $recordId)
            ->where('latest_flg', 1)
            ->first();

        $this->assertEquals($statusPending->id, $workflowValue->workflow_status_to_id,
            'Status must remain Pending Approval after unauthorized action.');
    }

    /**
     * Test: Workflow History - Verify full state change history
     */
    public function test_workflow_full_history()
    {
        // ---------------------------------------------------------
        // Step 1 - Create Data & Submit
        // ---------------------------------------------------------
        $this->actingAs($this->submitterLoginUser, 'admin');

        $customTable = CustomTable::getEloquent($this->tableName);
        $model = $this->getUnusedExistingRecord($customTable);
        if (!$model) {
            $model = $customTable->getValueModel();
            $model->setValue('test_name', 'History Test ' . date('YmdHis'));
            $model->setValue('test_price', 450000);
            $model->save();
        }
        $recordId = $model->id;

        $this->assertNotNull($recordId, 'Data record not created or found.');

        $actionSubmit = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Submit')
            ->first();

        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionSubmit->id,
            'comment' => 'History Step 1: Submit',
        ]);
        $response->assertStatus(200);
        System::clearCache();

        // ---------------------------------------------------------
        // Step 2 - Approve
        // ---------------------------------------------------------
        $this->actingAs($this->approverLoginUser, 'admin');

        $actionApprove = WorkflowAction::where('workflow_id', $this->workflow->id)
            ->where('action_name', 'Approve')
            ->first();

        $response = $this->post("admin/data/{$this->tableName}/{$recordId}/actionClick", [
            'action_id' => $actionApprove->id,
            'comment' => 'History Step 2: Approve',
        ]);
        $response->assertStatus(200);
        System::clearCache();

        // ---------------------------------------------------------
        // Step 3 - Verify full history (2 entries: Submit + Approve)
        // ---------------------------------------------------------
        $allWorkflowValues = WorkflowValue::where('morph_type', $this->tableName)
            ->where('morph_id', $recordId)
            ->orderBy('id', 'asc')
            ->get();

        $this->assertGreaterThanOrEqual(2, $allWorkflowValues->count(),
            'Workflow history should have at least 2 entries. Found: ' . $allWorkflowValues->count());

        // Verify entry 1: Submit (status -> Pending Approval)
        $statusPending = WorkflowStatus::where('workflow_id', $this->workflow->id)
            ->where('status_name', 'Pending Approval')->first();
        $entry1 = $allWorkflowValues->first();
        $this->assertEquals($statusPending->id, $entry1->workflow_status_to_id,
            'History entry 1 should be Pending Approval.');
        $this->assertEquals('History Step 1: Submit', $entry1->comment);

        // Verify entry 2: Approve (status -> Approved)
        $statusApproved = WorkflowStatus::where('workflow_id', $this->workflow->id)
            ->where('status_name', 'Approved')->first();
        $entry2 = $allWorkflowValues->last();
        $this->assertEquals($statusApproved->id, $entry2->workflow_status_to_id,
            'History entry 2 should be Approved.');
        $this->assertEquals('History Step 2: Approve', $entry2->comment);

        // Verify only the last entry has latest_flg = 1
        $latestEntries = $allWorkflowValues->where('latest_flg', 1);
        $this->assertEquals(1, $latestEntries->count(),
            'Only 1 entry should be marked as latest_flg = 1.');
        $this->assertEquals($entry2->id, $latestEntries->first()->id,
            'Latest entry must be the last entry (Approved).');
    }
}
