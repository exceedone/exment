<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Controllers\WorkflowController;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
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

    /**
     * WF-24: a deactivated workflow is deleted softly and its records keep their history, so the
     * history still names its actions and statuses - the start status too, which only the
     * workflow knows. It answered 500 ("start_status_name" on null) once the workflow was gone.
     */
    public function testTheHistoryOfADeactivatedWorkflowIsStillShown(): void
    {
        $workflow = $this->createByDesigner('claude deactivated flow');
        $actions = WorkflowAction::where('workflow_id', $workflow->id)->get()->keyBy('action_name');
        $statuses = WorkflowStatus::where('workflow_id', $workflow->id)->get()->keyBy('status_name');

        // a record without any other history: go (start -> s1), then done (s1 -> s2)
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $custom_value = $custom_table->getValueModel()
            ->whereNotIn('id', WorkflowValue::where('morph_type', $custom_table->table_name)->pluck('morph_id'))
            ->firstOrFail();
        $this->executed($custom_value, $actions['go'], null, $statuses['s1']->id, false);
        $this->executed($custom_value, $actions['done'], $statuses['s1']->id, $statuses['s2']->id, true);

        $modal = admin_urls('data', $custom_table->table_name, $custom_value->id, 'workflowHistoryModal');
        $this->get($modal)->assertOk();

        $this->post(admin_urls('workflow', $workflow->id, 'activate'), ['activate_keyword' => Define::YES_KEYWORD])->assertOk();
        $this->post(admin_urls('workflow', $workflow->id, 'deactivate'), ['activate_keyword' => Define::YES_KEYWORD])->assertOk();
        $this->assertSoftDeleted('workflows', ['id' => $workflow->id]);
        // nothing cached from before the deactivation may stand in for the deleted workflow
        System::clearRequestSession();
        System::clearCache();

        $response = $this->get($modal);
        $response->assertOk();
        $body = (string) $response->json('body');
        $this->assertStringContainsString('claude deactivated flow', $body);
        $this->assertStringContainsString(esc_html(exmtrans('workflow.status_from_to_format', 'start', 's1')), $body);
        $this->assertStringContainsString(esc_html(exmtrans('workflow.status_from_to_format', 's1', 's2')), $body);

        // the history in the drawer of a kanban card reads the same values
        /** @var array<int, array<string, string>> $kanban_rows */
        $kanban_rows = $this->get(admin_urls('data', $custom_table->table_name, $custom_value->id, 'kanbanHistory'))->assertOk()->json('rows');
        $rows = collect($kanban_rows);
        $this->assertTrue($rows->contains(function ($row) {
            return $row['action'] === 'go' && $row['from'] === 'start' && $row['to'] === 's1';
        }), $rows->toJson());

        // a value led back to the start status names it as well
        $back = new WorkflowValue();
        $back->workflow_id = $workflow->id;
        $back->workflow_status_to_id = null;
        $this->assertSame('start', $back->workflow_status_name);
    }

    /**
     * WF-14: step 2 refuses a number of people out of 0 - 10 in "Condition to advance to next
     * status", and shows why in the options cell of the action. The refusal used to be silent:
     * the options field looked its errors up under keys validateData() does not use.
     */
    public function testStepTwoShowsTheErrorOfTheNumberOfPeopleOfAnAction(): void
    {
        foreach (['11', '-1'] as $count) {
            $workflow = $this->commonWorkflow("claude step two {$count}");
            $url = admin_urls('workflow', $workflow->id, 'edit?action=2');

            $response = $this->from($url)->put(admin_url("workflow/{$workflow->id}"), $this->stepTwoPayload($workflow, $count));
            $response->assertRedirect($url)->assertSessionHasErrors('workflow_actions.new_0.flow_next_count');
            $this->assertSame(0, WorkflowAction::where('workflow_id', $workflow->id)->count(), "count {$count} must not be saved");
            $messages = session('errors')->get('workflow_actions.new_0.flow_next_count');
            // the attribute is named, not shown as a translation key
            $this->assertStringNotContainsString('exment::', $messages[0]);
            $this->assertStringContainsString(exmtrans('workflow.flow_next_count'), $messages[0]);

            $page = $this->get($url);
            $page->assertOk();
            foreach ($messages as $message) {
                $page->assertSee($message);
            }
        }

        // 0 and 10 are in range and saved
        foreach (['0', '10'] as $count) {
            $workflow = $this->commonWorkflow("claude step two {$count}");
            $url = admin_urls('workflow', $workflow->id, 'edit?action=2');

            $this->from($url)->put(admin_url("workflow/{$workflow->id}"), $this->stepTwoPayload($workflow, $count))
                ->assertSessionHasNoErrors();
            $action = WorkflowAction::where('workflow_id', $workflow->id)->firstOrFail();
            $this->assertSame($count, strval($action->getOption('flow_next_count')));
        }
    }

    /**
     * TC-WT-43: a step waiting for two approvers keeps its status after the first approval. The
     * first approver has nothing left to run there - the task list leaves them out, and so do the
     * buttons of the record page and the execution itself, which recorded the approval twice -
     * while the second approver still has the action.
     */
    public function testAnApproverWhoAlreadyApprovedHasNothingLeftToRun(): void
    {
        $user1 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER1);
        $user2 = LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $workflow = $this->createByDesigner('claude two approvers', [
            'targets' => ['user' => [strval($user1->base_user_id), strval($user2->base_user_id)]],
            'flow_next_type' => 'all',
        ], TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $done = WorkflowAction::where('workflow_id', $workflow->id)->where('action_name', 'done')->firstOrFail();
        $statuses = WorkflowStatus::where('workflow_id', $workflow->id)->get()->keyBy('status_name');

        // a record both users may open, at s1, where "done" waits for user1 and user2
        $custom_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $custom_value = $custom_table->getValueModel()
            ->whereNotIn('id', WorkflowValue::where('morph_type', $custom_table->table_name)->pluck('morph_id'))
            ->firstOrFail();
        $this->executed($custom_value, WorkflowAction::where('workflow_id', $workflow->id)->where('action_name', 'go')->firstOrFail(), null, $statuses['s1']->id, true);

        $page = admin_urls('data', $custom_table->table_name, $custom_value->id);
        $click = admin_urls('data', $custom_table->table_name, $custom_value->id, 'actionClick');
        $statusTo = function () use ($custom_table, $custom_value) {
            return WorkflowValue::where('morph_type', $custom_table->table_name)->where('morph_id', $custom_value->id)
                ->where('latest_flg', 1)->value('workflow_status_to_id');
        };
        $approvals = function (LoginUser $user) use ($custom_table, $custom_value, $done) {
            return WorkflowValue::where('morph_type', $custom_table->table_name)->where('morph_id', $custom_value->id)
                ->where('workflow_action_id', $done->id)->where('created_user_id', $user->base_user_id)->count();
        };

        // user1 approves: one of two, the status stays
        $this->be($user1);
        $this->get($page)->assertOk()->assertSee(admin_urls('data', $custom_table->table_name, $custom_value->id, 'actionModal'), false);
        $this->post($click, ['action_id' => $done->id])->assertOk()->assertJson(['result' => true]);
        $this->assertSame(strval($statuses['s1']->id), strval($statusTo()));

        // user1: no button any more, and posting again is refused without a second approval
        System::clearRequestSession();
        $this->get($page)->assertOk()->assertDontSee(admin_urls('data', $custom_table->table_name, $custom_value->id, 'actionModal'), false);
        $this->post($click, ['action_id' => $done->id])->assertOk()->assertJson(['result' => false]);
        $this->assertSame(1, $approvals($user1));
        $this->assertSame(strval($statuses['s1']->id), strval($statusTo()));

        // user2 still has the action, and the second approval moves the record on
        $this->be($user2);
        System::clearRequestSession();
        $this->get($page)->assertOk()->assertSee(admin_urls('data', $custom_table->table_name, $custom_value->id, 'actionModal'), false);
        $this->post($click, ['action_id' => $done->id])->assertOk()->assertJson(['result' => true]);
        $this->assertSame(strval($statuses['s2']->id), strval($statusTo()));
    }

    protected function workflow(string $name): Workflow
    {
        return Workflow::where('workflow_view_name', $name)->firstOrFail();
    }

    /**
     * Create a common workflow on step 1: start -> end, no action yet.
     */
    protected function commonWorkflow(string $name): Workflow
    {
        $this->post(admin_url('workflow'), [
            'workflow_view_name' => $name,
            'workflow_type' => WorkflowType::COMMON,
            'start_status_name' => 'start',
            'workflow_statuses' => [
                'new_1' => ['status_name' => 'end', 'datalock_flg' => 0, 'order' => 0, '_remove_' => 0],
            ],
        ]);

        return $this->workflow($name);
    }

    /**
     * What step 2 posts for its first row, an action from the start status to "end".
     *
     * @return array<string, mixed>
     */
    protected function stepTwoPayload(Workflow $workflow, string $flow_next_count): array
    {
        $end = WorkflowStatus::where('workflow_id', $workflow->id)->where('status_name', 'end')->firstOrFail();

        return [
            'action' => 2,
            'options' => ['workflow_edit_flg' => 0, 'get_by_userinfo_base' => 'executed_user'],
            'workflow_actions' => [
                'new_0' => [
                    'action_name' => 'claude go',
                    'status_from' => Define::WORKFLOW_START_KEYNAME,
                    'work_condition_select' => $end->id,
                    'work_targets' => WorkflowWorkTargetType::getTargetTypeDefault(0),
                    'flow_next_type' => 'some',
                    'flow_next_count' => $flow_next_count,
                    'comment_type' => 'nullable',
                    'id' => '',
                    '_remove_' => 0,
                ],
            ],
        ];
    }

    /**
     * A workflow value of $custom_value as WorkflowAction::executeAction() leaves it.
     *
     * @param mixed $status_from
     * @param mixed $status_to
     */
    protected function executed(CustomValue $custom_value, WorkflowAction $action, $status_from, $status_to, bool $latest): WorkflowValue
    {
        $value = new WorkflowValue();
        $value->workflow_id = $action->workflow_id;
        $value->morph_type = $custom_value->custom_table->table_name;
        $value->morph_id = $custom_value->id;
        $value->workflow_action_id = $action->id;
        $value->workflow_status_from_id = $status_from;
        $value->workflow_status_to_id = $status_to;
        $value->action_executed_flg = 0;
        $value->latest_flg = $latest ? 1 : 0;
        $value->save();

        return $value;
    }

    /**
     * Create a table workflow from the designer: start -> s1 -> s2 (completed), on custom_value_edit
     * unless another table is named.
     *
     * @param array<string, mixed> $done what the action "done" (s1 -> s2) has instead
     */
    protected function createByDesigner(string $name, array $done = [], string $table_name = TestDefine::TESTDATA_TABLE_NAME_EDIT): Workflow
    {
        $custom_table = CustomTable::getEloquent($table_name);
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
                array_merge($action('m2', 'done', 'n1', 'n2'), $done),
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
