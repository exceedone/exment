<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Controllers\WorkflowController;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\WorkflowType;
use Exceedone\Exment\Enums\WorkflowWorkTargetType;
use Exceedone\Exment\Middleware\Initialize;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use ExmentAdminCore\Admin\Admin;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\ViewErrorBag;

/**
 * The workflow designer (/admin/workflow/{id}/design) and the step 1 / step 2
 * screens it shares its tables with.
 *
 * Every test drives the controller the way the screen does - the designer's
 * JSON post, step 1's save hook, step 2's form - against the workflows
 * WorkflowTestDataSeeder creates:
 *   - workflow_common_company        completed setting, statuses middle / temp / end
 *   - workflow_common_no_complete    setting not completed, statuses waiting / completed
 *   - workflow_for_individual_table  completed setting; action3 sends status1 back to start
 */
class WorkflowDesignFixTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    /**
     * Once the setting is completed, step 1 no longer lets a status be added,
     * deleted or moved, and the completed status follows from that order. The
     * designer must refuse the same, and still allow a rename.
     *
     * @return void
     */
    public function testCompletedSettingLocksTheStatusesInTheDesigner()
    {
        $workflow = $this->workflow('workflow_common_company');
        // A status no record uses, so that removing it is refused for the lock
        // and not because data sits in it.
        $spare = $this->createStatus($workflow, 'spare');

        $data = $this->designData($workflow);
        $this->assertTrue($data['activated'], 'The fixture must be a completed setting.');
        $base = $this->payloadOf($data);

        $payload = $base;
        $payload['statuses'][] = ['id' => null, 'key' => 'n1', 'name' => 'added', 'datalock' => 0, 'completed' => 0];
        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(422, $response->getStatusCode(), 'Adding a status to a completed setting must be refused.');
        $this->assertFalse(
            WorkflowStatus::where('workflow_id', $workflow->id)->where('status_name', 'added')->exists(),
            'The refused status must not be stored.'
        );

        $payload = $base;
        $payload['statuses'] = array_values(array_filter($payload['statuses'], function ($status) use ($spare) {
            return strval($status['id']) !== strval($spare->id);
        }));
        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(422, $response->getStatusCode(), 'Deleting a status of a completed setting must be refused.');
        $this->assertTrue(WorkflowStatus::where('id', $spare->id)->exists(), 'The status must still be there.');

        $payload = $base;
        $flipped = null;
        foreach ($payload['statuses'] as &$status) {
            if (!$status['completed']) {
                $status['completed'] = 1;
                $flipped = $status['id'];
                break;
            }
        }
        unset($status);
        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(422, $response->getStatusCode(), 'Changing the completed status of a completed setting must be refused.');
        $this->assertFalse(boolval($this->findStatus($flipped)->completed_flg), 'The completed flag must not change.');

        // A rename stays allowed, as it is on step 1.
        $payload = $base;
        $payload['statuses'][0]['name'] = 'renamed';
        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(200, $response->getStatusCode(), 'Renaming a status must still be allowed: ' . $response->getContent());
        $this->assertSame('renamed', $this->findStatus($payload['statuses'][0]['id'])->status_name);
    }

    /**
     * Before the setting is completed, step 1 marks exactly one status - the
     * last one - as completed and rewrites the flags on its next save. The
     * designer must not store more than one.
     *
     * @return void
     */
    public function testDesignerAllowsOnlyOneCompletedStatusBeforeTheSettingIsCompleted()
    {
        $workflow = $this->workflow('workflow_common_no_complete');
        $data = $this->designData($workflow);
        $this->assertFalse($data['activated'], 'The fixture must be a setting that is not completed.');

        $payload = $this->payloadOf($data);
        foreach ($payload['statuses'] as &$status) {
            $status['completed'] = 1;
        }
        unset($status);

        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(422, $response->getStatusCode(), 'Two completed statuses must be refused.');
        $this->assertContains(
            exmtrans('workflow.design.message.completed_only_one'),
            (array)array_get(json_decode(strval($response->getContent()), true), 'errors'),
            'The refusal must say why.'
        );
        $this->assertSame(
            1,
            WorkflowStatus::where('workflow_id', $workflow->id)->where('completed_flg', 1)->count(),
            'Only the original completed status may stay completed.'
        );
    }

    /**
     * A completed setting may hold several completed statuses (a template can
     * ship them). Saving step 1 - only a rename is possible there by then -
     * must not turn all but the last back into unfinished statuses.
     *
     * @return void
     */
    public function testStepOneKeepsTheCompletedFlagsOfACompletedSetting()
    {
        $workflow = $this->workflow('workflow_common_company');
        $first = $workflow->workflow_statuses()->first();
        $first->completed_flg = 1;
        $first->save();

        $this->bootAdmin();
        $form = $this->callProtectedMethod(new WorkflowController(), 'statusForm', $workflow->id);
        $form->edit($workflow->id);
        $this->callProtectedMethod($form, 'callSavedInTransaction');

        $this->assertTrue(
            boolval($this->findStatus($first->id)->completed_flg),
            'Step 1 must keep a completed flag of a completed setting.'
        );
    }

    /**
     * Step 2 shows the action that leaves the start status in its first row:
     * that row has no delete button and its work target is fixed. Actions
     * come back in id order, and the designer can leave another action with
     * the lowest id, so the rows have to be put start-first.
     *
     * @return void
     */
    public function testStepTwoListsTheStartActionFirst()
    {
        $workflow = $this->workflow('workflow_for_individual_table');
        $this->redrawActionsBeforeTheSendBack($workflow);

        $this->bootAdmin();
        $form = $this->callProtectedMethod(new WorkflowController(), 'actionForm', $workflow->id);
        $form->edit($workflow->id);

        $first = $form->model()->workflow_actions->first();
        $this->assertSame('start', strval($first->status_from), 'The first row of step 2 must be an action from the start status.');
    }

    /**
     * The ignore_work box of step 2 must follow the row's own status_from.
     * The first row used to never draw it, and a missing box is stored as 0,
     * so an action from another status that ended up first lost its
     * ignore_work on every save of step 2.
     *
     * @return void
     */
    public function testStepTwoKeepsIgnoreWorkOfAnActionThatIsNotFromStart()
    {
        $workflow = $this->workflow('workflow_for_individual_table');
        $send_back = $this->redrawActionsBeforeTheSendBack($workflow);

        $rows = $this->stepTwoRows($workflow);
        $this->assertArrayHasKey(strval($send_back->id), $rows, 'The send-back action must be listed.');
        $row = $rows[strval($send_back->id)];
        $this->assertMatchesRegularExpression(
            '#name="workflow_actions\[' . $send_back->id . '\]\[ignore_work\]"[^>]*checked#',
            $row,
            'The send-back action must keep a checked ignore_work box.'
        );
    }

    /**
     * Only the first row - the start action that has to stay a work action -
     * goes without the ignore_work box. Another action from the start
     * status, such as applying on someone's behalf, has always offered it,
     * and a box that is not drawn is saved as 0, so hiding it there too
     * dropped that action's ignore_work on every save of step 2.
     *
     * @return void
     */
    public function testStepTwoKeepsIgnoreWorkOfASecondStartAction()
    {
        $workflow = $this->workflow('workflow_for_individual_table');
        $payload = $this->payloadOf($this->designData($workflow));
        $proxy = null;
        foreach ($payload['actions'] as $action) {
            if ($action['status_from'] === 'start') {
                $proxy = $action;
                break;
            }
        }
        $this->assertNotNull($proxy, 'The fixture must have a start action.');
        $proxy['id'] = null;
        $proxy['key'] = 'proxy';
        $proxy['name'] = 'proxy apply';
        $proxy['ignore_work'] = 1;
        foreach ($proxy['destinations'] as &$destination) {
            $destination['id'] = null;
        }
        unset($destination);
        $payload['actions'][] = $proxy;

        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(200, $response->getStatusCode(), 'Adding a second start action must save: ' . $response->getContent());
        $second = WorkflowAction::where('workflow_id', $workflow->id)->where('action_name', 'proxy apply')->firstOrFail();
        $this->assertTrue(boolval($second->ignore_work), 'The second start action must be stored as ignore_work.');
        System::clearRequestSession();
        System::clearCache();

        $rows = $this->stepTwoRows($workflow);
        $first_id = (string)array_key_first($rows);
        $this->assertNotSame(strval($second->id), $first_id, 'The second start action must not be the first row.');
        $this->assertStringNotContainsString(
            '[ignore_work]',
            $rows[$first_id],
            'The first row must still go without an ignore_work box.'
        );
        $this->assertArrayHasKey(strval($second->id), $rows, 'The second start action must be listed.');
        $this->assertMatchesRegularExpression(
            '#name="workflow_actions\[' . $second->id . '\]\[ignore_work\]"[^>]*checked#',
            $rows[strval($second->id)],
            'The second start action must keep a checked ignore_work box.'
        );
    }

    /**
     * Step 2 offers only "set in advance" on the first action out of the
     * start status. The designer must refuse "get from the user's info"
     * there, name the action in the message, and still accept it on any
     * other action - including a start action added after the saved one,
     * wherever it sits in the payload.
     *
     * @return void
     */
    public function testDesignerAcceptsOnlySetInAdvanceOnTheFirstAction()
    {
        $boss = CustomColumn::getEloquent('boss', SystemTableName::USER);
        $this->assertNotNull($boss, 'The fixture user table must have the boss column.');
        $by_userinfo = function (array $action) use ($boss) {
            $action['work_target_type'] = WorkflowWorkTargetType::GET_BY_USERINFO;
            $action['targets'] = ['login_user_column' => [strval($boss->id)]];
            return $action;
        };
        $table_id = CustomTable::getEloquent('custom_value_edit')->id;

        $payload = $this->newTablePayload($table_id);
        $payload['actions'][0] = $by_userinfo($payload['actions'][0]);
        $response = $this->createDesign($payload);
        $this->assertSame(422, $response->getStatusCode(), 'The first action must not take its users from the user info.');
        $this->assertContains(
            exmtrans('workflow.design.message.first_start_fix_only')
                . ' (' . exmtrans('workflow.design.fmt_quote', ['name' => 'go']) . ')',
            json_decode((string)$response->getContent(), true)['errors'],
            'The message must name the first action.'
        );

        $payload = $this->newTablePayload($table_id);
        $payload['actions'][1] = $by_userinfo($payload['actions'][1]);
        $response = $this->createDesign($payload);
        $this->assertSame(200, $response->getStatusCode(), 'A later action may take its users from the user info: ' . $response->getContent());

        $workflow = $this->workflow('workflow_for_individual_table');
        $payload = $this->payloadOf($this->designData($workflow));
        $later = null;
        foreach ($payload['actions'] as $action) {
            if ($action['status_from'] === 'start') {
                $later = $action;
                break;
            }
        }
        $this->assertNotNull($later, 'The fixture must have a start action.');
        $later = $by_userinfo($later);
        $later['id'] = null;
        $later['key'] = 'later';
        $later['name'] = 'later way in';
        foreach ($later['destinations'] as &$destination) {
            $destination['id'] = null;
        }
        unset($destination);
        array_unshift($payload['actions'], $later);
        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(200, $response->getStatusCode(), 'A start action added after the saved one is not the first: ' . $response->getContent());
    }

    /**
     * Creating a workflow from the designer must accept only a table step 1
     * offers - not a system table such as the user table.
     *
     * @return void
     */
    public function testDesignerCreateRefusesATableStepOneDoesNotOffer()
    {
        $before = Workflow::count();

        $payload = $this->newTablePayload(CustomTable::getEloquent(SystemTableName::USER)->id);
        $response = $this->createDesign($payload);
        $this->assertSame(422, $response->getStatusCode(), 'A workflow on the user table must be refused.');
        $this->assertSame($before, Workflow::count(), 'No workflow may be created.');

        $payload = $this->newTablePayload(CustomTable::getEloquent('custom_value_edit')->id);
        $response = $this->createDesign($payload);
        $this->assertSame(200, $response->getStatusCode(), 'A table step 1 offers must still work: ' . $response->getContent());
    }

    /**
     * The designer's boot data is printed into a script block. A name such as
     * "<!--<script>" must not be able to change how the browser reads it.
     *
     * @return void
     */
    public function testDesignerPageEscapesNamesInsideItsScript()
    {
        $workflow = $this->workflow('workflow_common_company');
        $status = $workflow->workflow_statuses()->first();
        $status->status_name = '<!--<script>';
        $status->save();

        $this->bootAdmin();
        $html = view('exment::workflow.design', ['design_data' => $this->designData($workflow)])->render();

        $this->assertStringNotContainsString('<!--<script>', $html, 'The name must not appear raw in the page.');
        // The form the boot data must carry it in, "<" and ">" as JSON hex escapes.
        $hex = trim(strval(json_encode('<!--<script>', JSON_HEX_TAG)), '"');
        $this->assertStringContainsString($hex, $html, 'The name must be hex-escaped in the boot data.');

        // The flow preview on step 2 prints the same names into its own script.
        System::clearRequestSession();
        System::clearCache();
        $preview = $this->callProtectedMethod(new WorkflowController(), 'getDesignerHtml', Workflow::find($workflow->id));
        $this->assertStringNotContainsString('<!--<script>', $preview, 'The step 2 preview must not print the name raw.');
        $this->assertStringContainsString($hex, $preview, 'The step 2 preview must hex-escape the name.');
    }

    /**
     * workflows.workflow_view_name holds 30 characters, so both the designer
     * and step 1 must refuse a longer name instead of failing the insert or
     * cutting the name.
     *
     * @return void
     */
    public function testWorkflowNameIsLimitedToThirtyCharacters()
    {
        $workflow = $this->workflow('workflow_common_no_complete');
        $payload = $this->payloadOf($this->designData($workflow));
        $payload['workflow_view_name'] = str_repeat('n', 31);

        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(422, $response->getStatusCode(), 'The designer must refuse a 31 character name.');

        $this->bootAdmin();
        $form = $this->callProtectedMethod(new WorkflowController(), 'statusForm', $workflow->id);
        $messages = $form->validationMessages(['workflow_view_name' => str_repeat('n', 31)]);
        $this->assertNotFalse($messages, 'Step 1 must report errors.');
        $this->assertTrue($messages->has('workflow_view_name'), 'Step 1 must refuse a 31 character name.');
    }

    /**
     * A record can be moved into a status while the designer is deleting it.
     * The value written in that moment must make the delete fail as a
     * conflict, never be erased together with the status.
     *
     * The other transaction is simulated by writing the value right after the
     * designer's own in-use check inside its transaction, the window in which
     * a concurrent workflow action could land.
     *
     * @return void
     */
    public function testDeletingAStatusNeverErasesAValueWrittenAfterTheCheck()
    {
        $workflow = $this->workflow('workflow_common_no_complete');
        $spare = $this->createStatus($workflow, 'spare');

        $payload = $this->payloadOf($this->designData($workflow));
        $payload['statuses'] = array_values(array_filter($payload['statuses'], function ($status) use ($spare) {
            return strval($status['id']) !== strval($spare->id);
        }));

        $written = null;
        \DB::listen(function (QueryExecuted $query) use (&$written, $workflow, $spare) {
            if (isset($written) || \DB::transactionLevel() < 2) {
                return;
            }
            if (strpos($query->sql, 'workflow_values') === false || stripos($query->sql, 'distinct') === false) {
                return;
            }
            $written = false;
            $value = new WorkflowValue();
            $value->workflow_id = $workflow->id;
            $value->morph_type = 'custom_value_access_all';
            $value->morph_id = 1;
            $value->workflow_status_to_id = $spare->id;
            $value->action_executed_flg = 0;
            $value->latest_flg = 0;
            $value->save();
            $written = true;
        });

        $response = $this->postDesign($workflow, $payload);

        $this->assertTrue($written === true, 'The concurrent value must have been written during the save.');
        $this->assertSame(409, $response->getStatusCode(), 'The delete must be answered as a conflict.');
        $this->assertTrue(WorkflowStatus::where('id', $spare->id)->exists(), 'The status must not be deleted.');
    }

    /**
     * The design mock is not used by any screen, and the publish step copies
     * the package's public folder to a place served without login.
     *
     * @return void
     */
    public function testDesignMockIsNotShipped()
    {
        $this->assertFileDoesNotExist(exment_package_path('public/vendor/exment/designs/workflow_designer.html'));
    }

    /**
     * Actions and workflows are found again by name on template import. One
     * action label used on two approval steps, and one workflow name used
     * twice, must still come in as separate records - each action with its own
     * authorities and branches.
     *
     * @return void
     */
    public function testTemplateImportKeepsSameNamedActionsAndWorkflowsApart()
    {
        $response = $this->createDesign($this->newTablePayload(CustomTable::getEloquent('custom_value_edit')->id));
        $this->assertSame(200, $response->getStatusCode(), (string)$response->getContent());
        $source = $this->workflow('designer create test');

        $item = $source->exportTemplateDesignerLayout(Workflow::findOrFail((int)$source->id)->getTemplateExportItems());
        $this->assertSame($source->suuid, array_get($item, 'suuid'), 'The export must carry the suuid.');
        foreach ($item['workflow_actions'] as &$action) {
            $action['action_name'] = 'Approve';
        }
        unset($action);
        $item['workflow_view_name'] = 'template copy';

        foreach ([short_uuid(), short_uuid()] as $suuid) {
            $item['suuid'] = $suuid;
            (new TemplateImporter())->import(['workflows' => [$item]]);

            $copy = Workflow::where('suuid', $suuid)->firstOrFail();
            $s1 = WorkflowStatus::where('workflow_id', $copy->id)->where('status_name', 's1')->firstOrFail();
            $actions = WorkflowAction::where('workflow_id', $copy->id)->get();
            $this->assertSame(2, $actions->count(), 'Both actions labelled "Approve" must be imported.');
            $this->assertEqualsCanonicalizing(
                ['start', strval($s1->id)],
                $actions->pluck('status_from')->map(fn ($from) => strval($from))->all(),
                'Each action must keep the status it leaves.'
            );
            foreach ($actions as $action) {
                $this->assertSame(1, $action->workflow_authorities()->count(), 'An action must keep its own authority.');
                $this->assertSame(1, $action->workflow_condition_headers()->count(), 'An action must keep its own branch.');
            }
        }
        $this->assertSame(2, Workflow::where('workflow_view_name', 'template copy')->count(), 'Two workflows sharing a name must not be merged.');

        // A template written before the suuid was exported still updates the
        // workflow of the same name instead of adding another one.
        unset($item['suuid']);
        $item['workflow_view_name'] = 'designer create test';
        (new TemplateImporter())->import(['workflows' => [$item]]);
        $this->assertSame(1, Workflow::where('workflow_view_name', 'designer create test')->count());
    }

    /**
     * A workflow the import cannot reproduce exactly comes in with every table
     * assignment inactive: a branch condition on a column missing here (an AND
     * branch left without conditions matches every record and can skip an
     * approval step), or names the import would merge.
     *
     * @return void
     */
    public function testTemplateImportSwitchesOffAWorkflowItCannotReproduce()
    {
        $table = CustomTable::getEloquent('custom_value_edit');
        $column = CustomColumn::where('custom_table_id', $table->id)->firstOrFail();
        $base = [
            'workflow_view_name' => 'no workflow has this name',
            'workflow_tables' => [['table_name' => $table->table_name, 'active_flg' => 1]],
            'workflow_statuses' => [['status_name' => 's1'], ['status_name' => 's2']],
            'workflow_actions' => [
                ['action_name' => 'Approve', 'status_from_name' => 'start', 'workflow_condition_headers' => [
                    ['status_to' => 's1', 'workflow_conditions' => [
                        ['condition_type' => ConditionType::COLUMN, 'target_column_ref' => $table->table_name . '.' . $column->column_name],
                    ]],
                    ['status_to' => 's2'],
                ]],
                ['action_name' => 'Approve', 'status_from_name' => 's1', 'workflow_condition_headers' => [['status_to' => 's2']]],
            ],
        ];
        $active = function (array $json) {
            Workflow::importReplaceJson($json);
            return array_get($json, 'workflow_tables.0.active_flg');
        };

        $this->assertSame(1, $active($base), 'A template reproduced exactly keeps its assignment active.');

        $json = $base;
        $json['workflow_actions'][0]['workflow_condition_headers'][0]['workflow_conditions'][0]['target_column_ref'] = $table->table_name . '.no_such_column';
        $this->assertSame(0, $active($json), 'A dropped branch condition must switch the workflow off.');

        $json = $base;
        $json['workflow_statuses'][1]['status_name'] = 's1';
        $this->assertSame(0, $active($json), 'Two statuses sharing a name must switch the workflow off.');

        $json = $base;
        $json['workflow_actions'][1]['status_from_name'] = 'start';
        $this->assertSame(0, $active($json), 'Two actions with one name leaving one status must switch the workflow off.');

        $json = $base;
        $json['workflow_actions'][0]['workflow_condition_headers'][1]['status_to'] = 's1';
        $this->assertSame(0, $active($json), 'Two branches of one action leading to one status must switch the workflow off.');

        // Without a suuid the workflow of the same name is matched, as before.
        $json = $base;
        Workflow::importReplaceJson($json);
        $this->assertNull($json['suuid']);
        $existing = $this->workflow('workflow_for_individual_table');
        $json = $base;
        $json['workflow_view_name'] = $existing->workflow_view_name;
        Workflow::importReplaceJson($json);
        $this->assertSame($existing->suuid, $json['suuid']);
    }

    // ------------------------------------------------------------------
    //  helpers
    // ------------------------------------------------------------------

    protected function workflow(string $name): Workflow
    {
        /** @var Workflow $workflow */
        $workflow = Workflow::where('workflow_view_name', $name)->firstOrFail();
        return $workflow;
    }

    /**
     * @param mixed $id
     */
    protected function findStatus($id): WorkflowStatus
    {
        return WorkflowStatus::where('id', $id)->firstOrFail();
    }

    protected function createStatus(Workflow $workflow, string $name): WorkflowStatus
    {
        $status = new WorkflowStatus();
        $status->workflow_id = $workflow->id;
        $status->status_type = 0;
        $status->status_name = $name;
        $status->datalock_flg = 0;
        $status->completed_flg = 0;
        $status->order = 0;
        $status->save();

        return $status;
    }

    /**
     * What the designer receives for a workflow.
     *
     * @return array<string, mixed>
     */
    protected function designData(Workflow $workflow): array
    {
        System::clearRequestSession();
        System::clearCache();
        return $this->callProtectedMethod(new WorkflowController(), 'getWorkflowDesignData', Workflow::find($workflow->id));
    }

    /**
     * What the designer posts back for the diagram it was given, unchanged.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function payloadOf(array $data): array
    {
        $key = function ($status_id) {
            return strval($status_id) === 'start' ? 'start' : 's' . $status_id;
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

    /**
     * POST a design the way the designer does.
     *
     * @param array<string, mixed> $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function postDesign(Workflow $workflow, array $payload)
    {
        System::clearRequestSession();
        System::clearCache();
        $request = Request::create(admin_urls('workflow', $workflow->id, 'design'), 'POST', ['design' => json_encode($payload)]);
        try {
            return (new WorkflowController())->designPost($request, $workflow->id);
        } catch (HttpResponseException $ex) {
            return $ex->getResponse();
        }
    }

    /**
     * POST a new workflow the way the designer's create screen does.
     *
     * @param array<string, mixed> $payload
     * @return \Symfony\Component\HttpFoundation\Response
     */
    protected function createDesign(array $payload)
    {
        System::clearRequestSession();
        System::clearCache();
        $request = Request::create(admin_url('workflow/design/create'), 'POST', ['design' => json_encode($payload)]);
        try {
            return (new WorkflowController())->designCreatePost($request);
        } catch (HttpResponseException $ex) {
            return $ex->getResponse();
        }
    }

    /**
     * A valid new table workflow: start -> s1 -> s2 (completed).
     *
     * @param mixed $custom_table_id
     * @return array<string, mixed>
     */
    protected function newTablePayload($custom_table_id): array
    {
        $action = function ($key, $name, $from, $to) {
            return [
                'id' => null, 'key' => $key, 'name' => $name, 'status_from' => $from, 'ignore_work' => 0,
                'work_target_type' => 'fix', 'targets' => ['system' => ['created_user']],
                'flow_next_type' => 'some', 'flow_next_count' => 1, 'comment_type' => 'nullable',
                'destinations' => [['id' => null, 'status_to' => $to, 'enabled' => 1]],
            ];
        };

        return [
            'workflow_type' => strval(WorkflowType::TABLE),
            'custom_table_id' => $custom_table_id,
            'workflow_view_name' => 'designer create test',
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
    }

    /**
     * Delete and redraw every action except the send-back one (action3 of
     * the fixture), the way an administrator redraws edges in the designer.
     * The redrawn actions get new ids, so the send-back action - which goes
     * from status1 back to start and is marked ignore_work - ends up with
     * the lowest id.
     *
     * @return WorkflowAction the send-back action
     */
    protected function redrawActionsBeforeTheSendBack(Workflow $workflow): WorkflowAction
    {
        $payload = $this->payloadOf($this->designData($workflow));
        $send_back_id = null;
        foreach ($payload['actions'] as &$action) {
            $back = false;
            foreach ($action['destinations'] as $destination) {
                $back = $back || $destination['status_to'] === 'start';
            }
            if ($back) {
                $send_back_id = $action['id'];
                continue;
            }
            $action['id'] = null;
            $action['key'] = 'm' . $action['key'];
            foreach ($action['destinations'] as &$destination) {
                $destination['id'] = null;
            }
            unset($destination);
        }
        unset($action);
        $this->assertNotNull($send_back_id, 'The fixture must have a send-back action.');

        $response = $this->postDesign($workflow, $payload);
        $this->assertSame(200, $response->getStatusCode(), 'Redrawing the actions must save: ' . $response->getContent());

        $send_back = WorkflowAction::where('id', $send_back_id)->firstOrFail();
        $lowest = WorkflowAction::where('workflow_id', $workflow->id)->orderBy('id')->firstOrFail();
        $this->assertSame(strval($send_back->id), strval($lowest->id), 'The send-back action must now have the lowest id.');
        $this->assertTrue(boolval($send_back->ignore_work), 'The send-back action must be marked ignore_work.');

        System::clearRequestSession();
        System::clearCache();

        return $send_back;
    }

    /**
     * Render step 2 and cut it into its action rows, keyed by action id.
     *
     * @return array<int|string, string>
     */
    protected function stepTwoRows(Workflow $workflow): array
    {
        $this->bootAdmin(admin_urls('workflow', $workflow->id, 'edit') . '?action=2');
        $form = $this->callProtectedMethod(new WorkflowController(), 'actionForm', $workflow->id);
        $html = (string)$form->edit($workflow->id)->render();

        $rows = [];
        preg_match_all('#<tr\b(.*?)</tr>#s', $html, $matches);
        foreach ($matches[0] as $row) {
            if (preg_match('#name="workflow_actions\[(\d+)\]\[status_from\]"#', $row, $key)) {
                $rows[$key[1]] = $row;
            }
        }

        return $rows;
    }

    /**
     * What the admin middleware does before a form is built or rendered.
     *
     * @param string|null $url
     * @return void
     */
    protected function bootAdmin(?string $url = null): void
    {
        if (isset($url)) {
            $request = Request::create($url, 'GET');
            $this->app->instance('request', $request);
            Facade::clearResolvedInstance('request');
            app('url')->setRequest($request);
        }

        Initialize::initializeConfig();
        Initialize::registeredLaravelAdmin();
        app(Admin::class)->bootstrap();
        view()->share('errors', new ViewErrorBag());
        System::clearRequestSession();
    }
}
