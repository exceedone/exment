<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\WorkflowController;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * The workflow settings screens (admin/workflow): step 1 store/update/destroy, the designer's
 * create and save posts, the work target and condition modals of step 2, step 3 (activate) and
 * the deactivation, and the table setting screen (workflow/beginning). Every request answers a
 * redirect or json, the workflows WorkflowTestDataSeeder creates are only read, and what the tests
 * create is rolled back.
 */
class WorkflowScreensCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    public function testStepOneStoresUpdatesAndDeletesAWorkflow(): void
    {
        $response = $this->post(admin_url('workflow'), [
            'workflow_view_name' => 'claude coverage flow',
            'workflow_type' => WorkflowType::COMMON,
            'start_status_name' => 'claude start',
            'workflow_statuses' => [
                'new_1' => ['status_name' => 'claude middle', 'datalock_flg' => 0, 'order' => 0, '_remove_' => 0],
                'new_2' => ['status_name' => 'claude end', 'datalock_flg' => 1, 'order' => 1, '_remove_' => 0],
            ],
        ]);
        $workflow = Workflow::where('workflow_view_name', 'claude coverage flow')->first();
        if ($workflow === null) {
            $this->fail('step 1 must create the workflow: ' . $response->getContent());
        }
        // a new workflow goes on to step 2, the actions
        $response->assertRedirect(admin_urls('workflow', $workflow->id, 'edit?action=2'));
        $this->assertDatabaseHas('workflows', [
            'id' => $workflow->id,
            'workflow_type' => WorkflowType::COMMON,
            'start_status_name' => 'claude start',
            'setting_completed_flg' => 0,
        ]);
        // the status with the highest order is the completed one
        $this->assertDatabaseHas('workflow_statuses', ['workflow_id' => $workflow->id, 'status_name' => 'claude middle', 'completed_flg' => 0]);
        $this->assertDatabaseHas('workflow_statuses', ['workflow_id' => $workflow->id, 'status_name' => 'claude end', 'datalock_flg' => 1, 'completed_flg' => 1]);

        $this->put(admin_url("workflow/{$workflow->id}"), [
            'workflow_view_name' => 'claude coverage flow (put)',
            'start_status_name' => 'claude start (put)',
        ])->assertRedirect(admin_url('workflow'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'workflow_view_name' => 'claude coverage flow (put)', 'start_status_name' => 'claude start (put)']);

        $this->patch(admin_url("workflow/{$workflow->id}"), [
            'workflow_view_name' => 'claude coverage flow (patch)',
            'start_status_name' => 'claude start (patch)',
        ])->assertRedirect(admin_url('workflow'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'workflow_view_name' => 'claude coverage flow (patch)', 'start_status_name' => 'claude start (patch)']);
        // the statuses were not part of the post and stay as they were
        $this->assertSame(2, WorkflowStatus::where('workflow_id', $workflow->id)->count());

        // a workflow whose setting is not completed is deleted for good, with its statuses
        $this->delete(admin_url("workflow/{$workflow->id}"))
            ->assertOk()
            ->assertJson(['status' => true]);
        $this->assertDatabaseMissing('workflows', ['id' => $workflow->id]);
        $this->assertDatabaseMissing('workflow_statuses', ['workflow_id' => $workflow->id]);
    }

    public function testDesignerCreatesAWorkflowAndSavesItsDesign(): void
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $workflow = $this->createByDesigner('claude designer flow');

        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'workflow_type' => WorkflowType::TABLE, 'start_status_name' => 'start']);
        $this->assertDatabaseHas('workflow_tables', ['workflow_id' => $workflow->id, 'custom_table_id' => $custom_table->id]);
        $this->assertSame(['s1', 's2'], WorkflowStatus::where('workflow_id', $workflow->id)->orderBy('order')->pluck('status_name')->all());
        $this->assertDatabaseHas('workflow_statuses', ['workflow_id' => $workflow->id, 'status_name' => 's2', 'completed_flg' => 1]);
        $this->assertSame(2, WorkflowAction::where('workflow_id', $workflow->id)->count());

        // what the designer posts back for the diagram it was given, renamed
        $payload = $this->designPayload($workflow);
        $payload['workflow_view_name'] = 'claude designer flow (saved)';
        $this->post(admin_urls('workflow', $workflow->id, 'design'), ['design' => json_encode($payload)])
            ->assertOk()
            ->assertJson(['result' => true, 'toastr' => trans('admin.save_succeeded')]);
        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'workflow_view_name' => 'claude designer flow (saved)']);
        $this->assertSame(2, WorkflowStatus::where('workflow_id', $workflow->id)->count());
        $this->assertSame(2, WorkflowAction::where('workflow_id', $workflow->id)->count());
    }

    public function testStepThreeActivatesAndTheDeleteButtonDeactivatesAWorkflow(): void
    {
        $workflow = $this->createByDesigner('claude activate flow');

        // the keyword typed into the dialog must be the yes keyword
        $this->post(admin_urls('workflow', $workflow->id, 'activate'), ['activate_keyword' => 'no'])
            ->assertStatus(400)
            ->assertJson(['result' => false, 'toastr' => exmtrans('error.mistake_keyword')]);
        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'setting_completed_flg' => 0]);

        $this->post(admin_urls('workflow', $workflow->id, 'activate'), ['activate_keyword' => Define::YES_KEYWORD])
            ->assertOk()
            ->assertJson(['result' => true, 'redirect' => admin_url('workflow/beginning')]);
        $this->assertDatabaseHas('workflows', ['id' => $workflow->id, 'setting_completed_flg' => 1]);

        // a completed workflow cannot be deleted; it is taken out of use by setting deleted_at
        $this->post(admin_urls('workflow', $workflow->id, 'deactivate'), ['activate_keyword' => Define::YES_KEYWORD])
            ->assertOk()
            ->assertJson(['result' => true, 'redirect' => admin_url('workflow')]);
        $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);
    }

    public function testStepTwoModalsAnswerTheirForms(): void
    {
        $workflow = $this->workflow('workflow_for_individual_table');

        // work targets of an action after the first one: the target type is a choice
        $response = $this->post(admin_urls('workflow', $workflow->id, 'modal/target'), [
            'index' => 1,
            'widgetmodal_uuid' => 'claude-target-modal',
        ]);
        $response->assertOk()->assertJson([
            'result' => true,
            'title' => exmtrans('workflow.work_targets'),
            'contentname' => 'workflow_actions_work_targets',
        ]);
        $body = (string) $response->json('body');
        $this->assertStringContainsString('work_target_type', $body);
        $this->assertStringContainsString('modal_system', $body);
        $this->assertStringContainsString('claude-target-modal', $body);

        // execution conditions: a table workflow offers three branches to the statuses of the workflow
        $status = WorkflowStatus::where('workflow_id', $workflow->id)->orderBy('order')->firstOrFail();
        $response = $this->post(admin_urls('workflow', $workflow->id, 'modal/condition'), [
            'widgetmodal_uuid' => 'claude-condition-modal',
        ]);
        $response->assertOk()->assertJson([
            'result' => true,
            'title' => exmtrans('workflow.work_conditions'),
            'contentname' => 'workflow_actions_work_conditions',
            'modalSize' => 'modal-xl',
        ]);
        $body = (string) $response->json('body');
        $this->assertStringContainsString('status_to_0', $body);
        $this->assertStringContainsString('status_to_2', $body);
        $this->assertStringContainsString(esc_html($status->status_name), $body);
    }

    public function testBeginningSavesWhichWorkflowATableUses(): void
    {
        $workflow = $this->workflow('workflow_common_company');
        $other = $this->workflow('workflow_common_no_complete');
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL);
        $url = admin_url('workflow/beginning');

        // two workflows switched on for one table over the same period are refused
        $this->from($url)->post($url, ['workflow_tables' => [$custom_table->id => ['workflows' => [
            $workflow->id => ['active_flg' => 1],
            $other->id => ['active_flg' => 1],
        ]]]])->assertRedirect($url)->assertSessionHasErrors("workflow_tables.{$custom_table->id}");
        $this->assertDatabaseMissing('workflow_tables', ['custom_table_id' => $custom_table->id, 'workflow_id' => $workflow->id]);

        $this->from($url)->post($url, ['workflow_tables' => [$custom_table->id => ['workflows' => [
            $workflow->id => ['active_flg' => 1, 'active_start_date' => '2026-01-01', 'active_end_date' => '2026-12-31'],
        ]]]])->assertRedirect($url)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('workflow_tables', [
            'custom_table_id' => $custom_table->id,
            'workflow_id' => $workflow->id,
            'active_flg' => 1,
            'active_start_date' => '2026-01-01',
            'active_end_date' => '2026-12-31',
        ]);
    }

    protected function workflow(string $name): Workflow
    {
        return Workflow::where('workflow_view_name', $name)->firstOrFail();
    }

    /**
     * Create a table workflow from the designer: start -> s1 -> s2 (completed) on custom_value_edit.
     */
    protected function createByDesigner(string $name): Workflow
    {
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $action = function ($key, $action_name, $from, $to) {
            return [
                'id' => null, 'key' => $key, 'name' => $action_name, 'status_from' => $from, 'ignore_work' => 0,
                'work_target_type' => 'fix', 'targets' => ['system' => ['created_user']],
                'flow_next_type' => 'some', 'flow_next_count' => 1, 'comment_type' => 'nullable',
                'destinations' => [['id' => null, 'status_to' => $to, 'enabled' => 1]],
            ];
        };
        $payload = [
            'workflow_type' => WorkflowType::TABLE,
            'custom_table_id' => $custom_table->id,
            'workflow_view_name' => $name,
            'start_status_name' => 'start',
            'statuses' => [
                ['id' => null, 'key' => 'n1', 'name' => 's1', 'datalock' => 0, 'completed' => 0],
                ['id' => null, 'key' => 'n2', 'name' => 's2', 'datalock' => 0, 'completed' => 1],
            ],
            'actions' => [
                $action('m1', 'go', 'start', 'n1'),
                $action('m2', 'done', 'n1', 'n2'),
            ],
            'layout' => ['enabled' => true, 'pos' => []],
        ];

        $response = $this->post(admin_url('workflow/design/create'), ['design' => json_encode($payload)]);
        $response->assertOk()->assertJson(['result' => true]);

        $workflow = Workflow::where('workflow_view_name', $name)->first();
        if ($workflow === null) {
            $this->fail('the designer must create the workflow: ' . $response->getContent());
        }
        // the designer moves on to the screen of the workflow it created
        $response->assertJsonPath('data.redirect', admin_urls('workflow', $workflow->id, 'design'));

        return $workflow;
    }

    /**
     * What the designer posts back, unchanged, for the diagram it is given for a workflow.
     *
     * @return array<string, mixed>
     */
    protected function designPayload(Workflow $workflow): array
    {
        System::clearRequestSession();
        System::clearCache();
        $data = (array) $this->callProtectedMethod(new WorkflowController(), 'getWorkflowDesignData', Workflow::findOrFail($workflow->id));

        $key = function ($status_id) {
            return strval($status_id) === Define::WORKFLOW_START_KEYNAME ? Define::WORKFLOW_START_KEYNAME : 's' . $status_id;
        };
        $payload = [
            'updated_at' => $data['updated_at'],
            'workflow_view_name' => $data['workflow_view_name'],
            'start_status_name' => $data['start_status_name'],
            'statuses' => [],
            'actions' => [],
            'layout' => ['enabled' => true, 'pos' => []],
        ];
        foreach ($data['statuses'] as $status) {
            $payload['statuses'][] = [
                'id' => $status['id'],
                'key' => $key($status['id']),
                'name' => $status['name'],
                'datalock' => $status['datalock'] ? 1 : 0,
                'completed' => $status['completed'] ? 1 : 0,
            ];
        }
        foreach ($data['actions'] as $action) {
            $destinations = [];
            foreach ($action['destinations'] as $destination) {
                $destinations[] = [
                    'id' => $destination['id'],
                    'status_to' => $key($destination['status_to']),
                    'enabled' => $destination['enabled'] ? 1 : 0,
                    'condition_join' => $destination['condition_join'],
                    'condition_reverse' => $destination['condition_reverse'],
                    'workflow_conditions' => $destination['workflow_conditions'],
                ];
            }
            $payload['actions'][] = [
                'id' => $action['id'],
                'key' => 'a' . $action['id'],
                'name' => $action['name'],
                'status_from' => $key($action['status_from']),
                'ignore_work' => $action['ignore_work'] ? 1 : 0,
                'work_target_type' => $action['work_target_type'],
                'targets' => $action['targets'],
                'flow_next_type' => $action['flow_next_type'],
                'flow_next_count' => $action['flow_next_count'],
                'comment_type' => $action['comment_type'],
                'destinations' => $destinations,
            ];
        }

        return $payload;
    }
}
