<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\Workflow;
use Exceedone\Exment\Model\WorkflowAction;
use Exceedone\Exment\Model\WorkflowStatus;
use Exceedone\Exment\Model\WorkflowValue;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;

/**
 * #1623: the button of a data update setting on the data detail page, for a data the workflow
 * locks (a status that does not allow editing).
 *
 * Since v6.2.5 the button is shown only when the data can be edited, so it is hidden for a locked
 * data. That stays the default. The setting has a new option, "Show Button for Data Locked by
 * Workflow" (options.button_workflow_locked), to show the button also for such a data.
 *
 * The table is "custom_value_edit_all", which has the workflow "workflow_common_company": its
 * status "temp" locks the data.
 */
class OperationButtonWorkflowLockedTest extends FeatureTestBase
{
    use DatabaseTransactions;

    private const TABLE = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;
    private const LOCKED_STATUS = 'temp';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
        // saving a record notifies the table's notify targets
        Notification::fake();
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testButtonIsShownForADataThatCanBeEdited(): void
    {
        $custom_value = $this->createValue();
        $operation = $this->createOperation(false);
        $operation_locked = $this->createOperation(true);

        $content = $this->getShowContent($custom_value);

        $this->assertStringContainsString($this->buttonId($operation), $content);
        $this->assertStringContainsString($this->buttonId($operation_locked), $content);
    }

    public function testButtonIsHiddenForALockedDataByDefault(): void
    {
        $custom_value = $this->createValue();
        $operation = $this->createOperation(false);
        $this->lock($custom_value);

        $content = $this->getShowContent($custom_value);

        $this->assertStringNotContainsString($this->buttonId($operation), $content);
    }

    public function testButtonIsShownForALockedDataWhenTheOptionIsOn(): void
    {
        $custom_value = $this->createValue();
        $operation = $this->createOperation(false);
        $operation_locked = $this->createOperation(true);
        $this->lock($custom_value);

        $content = $this->getShowContent($custom_value);

        // only the operation whose option is on
        $this->assertStringContainsString($this->buttonId($operation_locked), $content);
        $this->assertStringNotContainsString($this->buttonId($operation), $content);
    }

    /**
     * The button shown for a locked data runs too (CustomOperation::enableButton); without the
     * option the request is refused as before.
     */
    public function testButtonForALockedDataRunsWhenTheOptionIsOn(): void
    {
        $custom_value = $this->createValue();
        $operation = $this->createOperation(false);
        $operation_locked = $this->createOperation(true);
        $this->lock($custom_value);

        $this->click($operation, $custom_value)->assertStatus(400)
            ->assertJson(['result' => false, 'swaltext' => \Exceedone\Exment\Enums\ErrorCode::WORKFLOW_LOCK()->getMessage()]);
        $this->click($operation_locked, $custom_value)->assertStatus(200)->assertJson(['result' => true]);
    }

    public function testInactiveOperationHasNoButtonEvenWhenTheOptionIsOn(): void
    {
        $custom_value = $this->createValue();
        $operation_locked = $this->createOperation(true, false);
        $this->lock($custom_value);

        $content = $this->getShowContent($custom_value);

        $this->assertStringNotContainsString($this->buttonId($operation_locked), $content);
    }

    public function testSettingPageHasTheOption(): void
    {
        $response = $this->get(admin_urls('operation', self::TABLE, 'create'));
        $response->assertStatus(200);

        $this->assertStringContainsString('options[button_workflow_locked]', (string) $response->getContent());
    }

    private function table(): CustomTable
    {
        $table = CustomTable::getEloquent(self::TABLE);
        if (!$table instanceof CustomTable) {
            $this->fail('the test data has no ' . self::TABLE . ' table');
        }

        return $table;
    }

    /**
     * A record of the table, created by the logged-in admin. It has no workflow value yet, so it
     * is not locked.
     */
    private function createValue(): CustomValue
    {
        $custom_value = $this->table()->getValueModel();
        if (!$custom_value instanceof CustomValue) {
            $this->fail('no value model for ' . self::TABLE);
        }
        $custom_value->setValue(['text' => 'issue 1623']);
        $custom_value->save();

        return $custom_value;
    }

    private function createOperation(bool $showForLocked, bool $active = true): CustomOperation
    {
        $operation = new CustomOperation();
        $operation->custom_table_id = $this->table()->id;
        $operation->operation_name = 'issue 1623 ' . short_uuid();
        $operation->operation_type = [CustomOperationType::BUTTON];
        $operation->setOption('button_workflow_locked', $showForLocked ? '1' : '0');
        $operation->active_flg = $active;
        $operation->save();

        return $operation;
    }

    /**
     * Move the record to the status "temp" of the workflow, which locks the data.
     */
    private function lock(CustomValue $custom_value): void
    {
        $workflow = Workflow::getWorkflowByTable($this->table());
        if (!$workflow instanceof Workflow) {
            $this->fail('the test data has no workflow for ' . self::TABLE);
        }

        $status = WorkflowStatus::where('workflow_id', $workflow->id)->where('status_name', self::LOCKED_STATUS)->first();
        if (!$status instanceof WorkflowStatus) {
            $this->fail('the workflow has no status ' . self::LOCKED_STATUS);
        }
        $this->assertTrue(boolval($status->datalock_flg));

        $action = WorkflowAction::where('workflow_id', $workflow->id)->first();
        if (!$action instanceof WorkflowAction) {
            $this->fail('the workflow has no action');
        }

        $value = new WorkflowValue();
        $value->workflow_id = $workflow->id;
        $value->morph_type = self::TABLE;
        $value->morph_id = $custom_value->id;
        $value->workflow_action_id = $action->id;
        $value->workflow_status_from_id = null;
        $value->workflow_status_to_id = $status->id;
        $value->action_executed_flg = 0;
        $value->latest_flg = 1;
        $value->save();

        System::clearRequestSession();
        $reloaded = $this->table()->getValueModel($custom_value->id);
        $this->assertInstanceOf(CustomValue::class, $reloaded);
        $this->assertTrue($reloaded->lockedWorkflow(), 'the record should be locked by the workflow');
    }

    /**
     * @return \Illuminate\Testing\TestResponse<\Symfony\Component\HttpFoundation\Response>
     */
    private function click(CustomOperation $operation, CustomValue $custom_value): \Illuminate\Testing\TestResponse
    {
        System::clearRequestSession();
        foreach ($this->app['router']->getRoutes()->getRoutes() as $route) {
            $route->flushController();
        }

        return $this->post(admin_urls('data', self::TABLE, $custom_value->id, 'operationClick'), [
            'suuid' => $operation->suuid,
        ], ['X-Requested-With' => 'XMLHttpRequest']);
    }

    private function getShowContent(CustomValue $custom_value): string
    {
        System::clearRequestSession();
        $response = $this->get(admin_urls('data', self::TABLE, $custom_value->id));
        $response->assertStatus(200);

        return (string) $response->getContent();
    }

    /**
     * The id of the button of the operation (Form\Tools\OperationButton).
     */
    private function buttonId(CustomOperation $operation): string
    {
        return 'menu_button_' . $operation->suuid;
    }
}
