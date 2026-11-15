<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\CustomOperationType;
use Exceedone\Exment\Enums\ErrorCode;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomOperation;
use Exceedone\Exment\Model\CustomOperationColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A data update (custom operation) is run by a button of the record's page or as a bulk update
 * of the list. The buttons are only shown to those who can edit, and the request they send
 * (admin/data/{table}/{id}/operationClick, admin/data/{table}/operationClick) holds to the same.
 *
 * user2 of the test data may only read the records of custom_value_view_all, and may edit
 * every record of custom_value_edit_all.
 *
 * Exment keeps the records and permissions it read in a static request cache
 * (System::requestSession), which a new PHP process empties on every request in production;
 * the tests empty it before each request and each change of user.
 *
 * Everything a test writes is rolled back.
 */
class CustomOperationPermissionTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const AJAX = ['X-Requested-With' => 'XMLHttpRequest'];

    protected const TABLE_VIEW = TestDefine::TESTDATA_TABLE_NAME_VIEW_ALL;
    protected const TABLE_EDIT = TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL;

    protected const UPDATED = 'changed by an operation';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        // saving a record notifies the table's notify targets
        Notification::fake();
        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
    }

    protected function tearDown(): void
    {
        System::clearRequestSession();
        parent::tearDown();
    }

    public function testAUserWhoMayOnlyReadTheRecordDoesNotUpdateItWithTheButton(): void
    {
        $operation = $this->operation(static::TABLE_VIEW);
        $record = $this->record(static::TABLE_VIEW, 'before');

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        // the user does read the record
        System::clearRequestSession();
        $this->get(admin_urls('webapi', 'data', static::TABLE_VIEW, $record->id), static::AJAX)->assertStatus(200);

        $this->click($operation, static::TABLE_VIEW, $record)
            ->assertStatus(400)
            ->assertJson(['result' => false, 'swaltext' => ErrorCode::PERMISSION_DENY()->getMessage()]);
        $this->assertSame('before', $this->stored($record)->getValue('text'));
    }

    public function testAUserWhoMayOnlyReadTheRecordsDoesNotUpdateThemAsABulkUpdate(): void
    {
        $operation = $this->operation(static::TABLE_VIEW);
        $one = $this->record(static::TABLE_VIEW, 'before one');
        $two = $this->record(static::TABLE_VIEW, 'before two');

        $this->actAs(TestDefine::TESTDATA_USER_LOGINID_USER2);
        $this->bulk($operation, static::TABLE_VIEW, [$one, $two])
            ->assertStatus(200)
            ->assertJson(['result' => false, 'message' => ErrorCode::PERMISSION_DENY()->getMessage()]);
        $this->assertSame('before one', $this->stored($one)->getValue('text'));
        $this->assertSame('before two', $this->stored($two)->getValue('text'));
    }

    public function testAUserWhoMayEditTheRecordStillUpdatesIt(): void
    {
        $operation = $this->operation(static::TABLE_EDIT);

        foreach ([TestDefine::TESTDATA_USER_LOGINID_USER2, TestDefine::TESTDATA_USER_LOGINID_ADMIN] as $login_id) {
            $this->actAs(TestDefine::TESTDATA_USER_LOGINID_ADMIN);
            $record = $this->record(static::TABLE_EDIT, 'before');
            $one = $this->record(static::TABLE_EDIT, 'before one');
            $two = $this->record(static::TABLE_EDIT, 'before two');

            $this->actAs($login_id);
            $this->click($operation, static::TABLE_EDIT, $record)
                ->assertStatus(200)
                ->assertJson(['result' => true]);
            $this->assertSame(static::UPDATED, $this->stored($record)->getValue('text'));

            $this->bulk($operation, static::TABLE_EDIT, [$one, $two])
                ->assertStatus(200)
                ->assertJson(['result' => true]);
            $this->assertSame(static::UPDATED, $this->stored($one)->getValue('text'));
            $this->assertSame(static::UPDATED, $this->stored($two)->getValue('text'));
        }
    }

    public function testARecordLockedByItsWorkflowIsNotUpdated(): void
    {
        // a record of the test data that its workflow status locks
        $locked = null;
        foreach ([static::TABLE_EDIT, TestDefine::TESTDATA_TABLE_NAME_EDIT] as $table_name) {
            foreach ($this->newRow($table_name)->newQueryWithoutScopes()->whereNull('deleted_at')->get() as $row) {
                if ($row instanceof CustomValue && $row->lockedWorkflow()) {
                    $locked = $row;
                    break 2;
                }
            }
        }
        if (!$locked instanceof CustomValue) {
            $this->markTestSkipped('the test data has no record locked by its workflow');
        }

        $table_name = strval($locked->custom_table->table_name);
        $operation = $this->operation($table_name);
        $before = $locked->getValue('text');
        $this->assertNotSame(static::UPDATED, $before);

        // even the administrator: the record's page shows no button for a locked record
        $this->click($operation, $table_name, $locked)
            ->assertStatus(400)
            ->assertJson(['result' => false, 'swaltext' => ErrorCode::WORKFLOW_LOCK()->getMessage()]);
        $this->assertSame($before, $this->stored($locked)->getValue('text'));

        $this->bulk($operation, $table_name, [$locked])
            ->assertStatus(200)
            ->assertJson(['result' => false, 'message' => ErrorCode::WORKFLOW_LOCK()->getMessage()]);
        $this->assertSame($before, $this->stored($locked)->getValue('text'));
    }

    public function testOnlyAnActiveButtonOrBulkOperationOfTheTableIsRun(): void
    {
        $record = $this->record(static::TABLE_EDIT, 'before');

        // of another table
        $other = $this->operation(static::TABLE_VIEW);
        // switched off
        $inactive = $this->operation(static::TABLE_EDIT, [CustomOperationType::BUTTON, CustomOperationType::BULK_UPDATE], false);
        // run when a record is saved, not by a button
        $onSave = $this->operation(static::TABLE_EDIT, [CustomOperationType::UPDATE]);

        foreach ([$other, $inactive, $onSave] as $operation) {
            $this->click($operation, static::TABLE_EDIT, $record)->assertStatus(404);
            $this->bulk($operation, static::TABLE_EDIT, [$record])->assertStatus(404);
            $this->assertSame('before', $this->stored($record)->getValue('text'));
        }
    }

    // helpers ----------------------------------------------------

    /**
     * Log in and forget what the previous user read.
     */
    protected function actAs(string $login_id): void
    {
        $user = LoginUser::find($login_id);
        if (!$user instanceof LoginUser) {
            $this->fail("the test data has no login user $login_id");
        }
        $this->be($user);
        System::clearRequestSession();
    }

    protected function table(string $table_name): CustomTable
    {
        $table = CustomTable::getEloquent($table_name);
        if (!$table instanceof CustomTable) {
            $this->fail("the test data has no $table_name table");
        }

        return $table;
    }

    protected function newRow(string $table_name): CustomValue
    {
        $row = $this->table($table_name)->getValueModel();
        if (!$row instanceof CustomValue) {
            $this->fail("no value model for $table_name");
        }

        return $row;
    }

    /**
     * A new record of the table, created by the user who is logged in.
     */
    protected function record(string $table_name, string $text): CustomValue
    {
        $row = $this->newRow($table_name);
        $row->setValue(['text' => $text]);
        $row->save();

        return $row;
    }

    /**
     * The record as the database holds it now, whoever is logged in.
     */
    protected function stored(CustomValue $row): CustomValue
    {
        $stored = $row->newQueryWithoutScopes()->where('id', $row->id)->first();
        if (!$stored instanceof CustomValue) {
            $this->fail('the record is no longer in the database');
        }

        return $stored;
    }

    /**
     * An operation of the table that writes UPDATED to the "text" column, without conditions.
     *
     * @param array<int, string> $types
     */
    protected function operation(string $table_name, array $types = [CustomOperationType::BUTTON, CustomOperationType::BULK_UPDATE], bool $active = true): CustomOperation
    {
        $table = $this->table($table_name);
        $text = CustomColumn::getEloquent('text', $table);
        if (!$text instanceof CustomColumn) {
            $this->fail("the table $table_name has no text column");
        }

        $operation = CustomOperation::create([
            'custom_table_id' => $table->id,
            'operation_type' => $types,
            'operation_name' => 'permission test operation',
            'options' => ['condition_join' => 'and', 'active_flg' => $active ? 1 : 0],
        ]);
        CustomOperationColumn::create([
            'custom_operation_id' => $operation->id,
            'view_column_type' => ConditionType::COLUMN,
            'view_column_target_id' => $text->id,
            'update_value_text' => static::UPDATED,
            'options' => ['operation_update_type' => 'default'],
        ]);

        $operation = CustomOperation::find($operation->id);
        if (!$operation instanceof CustomOperation) {
            $this->fail('the operation was not saved');
        }
        $this->assertNotEmpty($operation->suuid);
        $this->assertSame($active, boolval($operation->active_flg));

        return $operation;
    }

    /**
     * The button of the record's page.
     *
     * @return TestResponse<Response>
     */
    protected function click(CustomOperation $operation, string $table_name, CustomValue $record): TestResponse
    {
        System::clearRequestSession();

        return $this->post(admin_urls('data', $table_name, $record->id, 'operationClick'), [
            'suuid' => $operation->suuid,
        ], static::AJAX);
    }

    /**
     * The bulk update of the list.
     *
     * @param array<int, CustomValue> $records
     * @return TestResponse<Response>
     */
    protected function bulk(CustomOperation $operation, string $table_name, array $records): TestResponse
    {
        $ids = [];
        foreach ($records as $record) {
            $ids[] = intval($record->id);
        }
        System::clearRequestSession();

        return $this->post(admin_urls('data', $table_name, 'operationClick'), [
            'suuid' => $operation->suuid,
            'id' => implode(',', $ids),
        ], static::AJAX);
    }
}
