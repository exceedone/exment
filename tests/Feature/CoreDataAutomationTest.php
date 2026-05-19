<?php

namespace Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Model\CustomViewFilter;
use Exceedone\Exment\Model\CustomViewSort;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\DataSubmitRedirectEx;
use Exceedone\Exment\Enums\ViewType;
use Exceedone\Exment\Enums\ViewKindType;
use Exceedone\Exment\Enums\FilterOption;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CoreDataAutomationTest extends TestCase
{
    /**
     * @var LoginUser
     */
    protected $adminUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup: Initialize Admin User and log in
        $this->adminUser = LoginUser::find(1) ?? LoginUser::first();

        if (!$this->adminUser) {
            $this->markTestSkipped('No user found in database to run the tests.');
        }

        // Log in using web or admin guard
        $this->actingAs($this->adminUser, 'admin');
        $this->actingAs($this->adminUser, 'web');

        // Set user context for Exment (necessary for created_user_id, etc.)
        \Exment::setGuard('admin');
    }

    /**
     * Test Scenario: Core Data Initialization and Management
     *
     * Flow:
     *   Step 1: Create CustomTable via HTTP POST (controller)
     *   Step 2: Create CustomColumn via HTTP POST (controller)
     *   Step 3: Insert data via Model API (CustomValue)
     *   Step 4: Update data via Model API
     *   Step 5: Do not delete data (No Teardown) to let subsequent tests reuse it.
     */
    public function test_core_data_management_flow()
    {
        $timestamp = date('YmdHis');
        $tableName = 'uni_table_' . $timestamp;

        // ---------------------------------------------------------
        // Step 1 - Create Table (HTTP POST)
        // ---------------------------------------------------------
        $tablePayload = [
            'table_name' => $tableName,
            'table_view_name' => 'Automation Test Table ' . $timestamp,
            'description' => 'Auto-created table from PHPUnit Test ' . $timestamp,
            'order' => 10,
            'options' => [
                'color' => '#ffffff',
                'search_enabled' => '1',
                'use_label_id_flg' => '0',
                'one_record_flg' => '0',
                'attachment_flg' => '1',
                'comment_flg' => '1',
                'revision_flg' => '1',
                'revision_count' => 100,
                'data_submit_redirect' => DataSubmitRedirectEx::INHERIT,
                'all_user_editable_flg' => '1',
                'all_user_viewable_flg' => '1',
                'all_user_accessable_flg' => '1',
            ],
            'add_parent_menu_flg' => '0',
            'add_notify_flg' => '0',
        ];

        $response = $this->post(route('exment.table.store'), $tablePayload);
        if ($response->isRedirect() && session('errors')) {
            $this->fail('Table creation failed: ' . json_encode(session('errors')->toArray()));
        }
        $response->assertStatus(302);

        $this->assertDatabaseHas('custom_tables', [
            'table_name' => $tableName,
            'table_view_name' => 'Automation Test Table ' . $timestamp,
        ]);

        System::clearCache();

        $customTable = CustomTable::where('table_name', $tableName)->first();
        $this->assertNotNull($customTable, 'Table not found in custom_tables.');

        // ---------------------------------------------------------
        // Step 2 - Create Columns (HTTP POST)
        // ---------------------------------------------------------
        // Column 1: test_name (Text)
        $column1Payload = [
            'column_name' => 'test_name',
            'column_view_name' => 'Test Name',
            'column_type' => 'text',
            'order' => 10,
            'options' => [
                'required' => '1',
                'unique' => '0',
                'index_enabled' => '1',
                'freeword_search' => '1',
                'max_length' => 255,
            ],
            'add_custom_form_flg' => '1',
            'add_custom_view_flg' => '1',
            'add_table_label_flg' => '0',
        ];

        $response = $this->post(route('exment.column.store', ['tableKey' => $tableName]), $column1Payload);
        if ($response->isRedirect() && session('errors')) {
            $this->fail('Column 1 (test_name) creation failed: ' . json_encode(session('errors')->toArray()));
        }
        $response->assertStatus(302);
        System::clearCache();

        // Column 2: test_price (Integer)
        $column2Payload = [
            'column_name' => 'test_price',
            'column_view_name' => 'Test Price',
            'column_type' => 'integer',
            'order' => 20,
            'options' => [
                'required' => '1',
                'unique' => '0',
                'index_enabled' => '0',
            ],
            'add_custom_form_flg' => '1',
            'add_custom_view_flg' => '1',
            'add_table_label_flg' => '0',
        ];

        $response = $this->post(route('exment.column.store', ['tableKey' => $tableName]), $column2Payload);
        if ($response->isRedirect() && session('errors')) {
            $this->fail('Column 2 (test_price) creation failed: ' . json_encode(session('errors')->toArray()));
        }
        $response->assertStatus(302);
        System::clearCache();

        // Assert columns saved in database
        $this->assertDatabaseHas('custom_columns', [
            'custom_table_id' => $customTable->id,
            'column_name' => 'test_name',
        ]);
        $this->assertDatabaseHas('custom_columns', [
            'custom_table_id' => $customTable->id,
            'column_name' => 'test_price',
        ]);

        // ---------------------------------------------------------
        // Step 3 - Insert Data (Model API)
        // ---------------------------------------------------------
        $customTable = CustomTable::getEloquent($tableName);
        $model = $customTable->getValueModel();

        $model->setValue('test_name', 'Test Product 01 ' . $timestamp);
        $model->setValue('test_price', 150000);
        $model->save();

        $this->assertNotNull($model->id, 'Record not created (id = null).');

        // Verify: read back from DB
        System::clearCache();
        $record = $customTable->getValueModel($model->id);
        $this->assertNotNull($record, 'Record not found after creation.');
        $this->assertEquals('Test Product 01 ' . $timestamp, $record->getValue('test_name'));
        $this->assertEquals(150000, $record->getValue('test_price'));

        // ---------------------------------------------------------
        // Step 4 - Update Data (Model API)
        // ---------------------------------------------------------
        $record->setValue('test_name', 'Test Product 01 (Updated) ' . $timestamp);
        $record->setValue('test_price', 250000);
        $record->save();

        System::clearCache();

        // Verify updated data
        $updatedRecord = $customTable->getValueModel($record->id);
        $this->assertEquals('Test Product 01 (Updated) ' . $timestamp, $updatedRecord->getValue('test_name'));
        $this->assertEquals(250000, $updatedRecord->getValue('test_price'));

        // ---------------------------------------------------------
        // Do not delete test data after completion as requested by User
        // ---------------------------------------------------------
    }

    /**
     * Test: Read Data - List (GET List) and Detail (GET Detail)
     */
    public function test_read_data_list_and_detail()
    {
        $timestamp = date('YmdHis');

        // Find the previously created table
        $customTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$customTable) {
            $this->markTestSkipped('No uni_table_* found to test Read. Need to run test_core_data_management_flow first.');
        }

        $tableName = $customTable->table_name;

        // Create some records to test list
        $model1 = $customTable->getValueModel();
        $model1->setValue('test_name', 'Read Test A ' . $timestamp);
        $model1->setValue('test_price', 100000);
        $model1->save();

        $model2 = $customTable->getValueModel();
        $model2->setValue('test_name', 'Read Test B ' . $timestamp);
        $model2->setValue('test_price', 200000);
        $model2->save();

        System::clearCache();

        // ---------------------------------------------------------
        // Step 1 - GET List (WebAPI)
        // ---------------------------------------------------------
        $response = $this->getJson(
            config('admin.route.prefix', 'admin') . "/webapi/data/{$tableName}"
        );

        $this->assertTrue(
            in_array($response->status(), [200]),
            'GET List failed. Status: ' . $response->status() . ' Body: ' . $response->content()
        );

        $listData = $response->json();
        $this->assertNotEmpty($listData, 'List response is empty.');

        // ---------------------------------------------------------
        // Step 2 - GET Detail (WebAPI)
        // ---------------------------------------------------------
        $response = $this->getJson(
            config('admin.route.prefix', 'admin') . "/webapi/data/{$tableName}/{$model1->id}"
        );

        $this->assertTrue(
            in_array($response->status(), [200]),
            'GET Detail failed. Status: ' . $response->status() . ' Body: ' . $response->content()
        );

        $detailData = $response->json();
        $this->assertEquals($model1->id, $detailData['id'] ?? null, 'Detail response does not match record ID.');

        // ---------------------------------------------------------
        // Step 3 - Verify via Model API
        // ---------------------------------------------------------
        $record = $customTable->getValueModel($model1->id);
        $this->assertNotNull($record, 'Record not found via Model API.');
        $this->assertEquals('Read Test A ' . $timestamp, $record->getValue('test_name'));
        $this->assertEquals(100000, $record->getValue('test_price'));
    }

    /**
     * Test: Validation failure - required field left blank
     */
    public function test_validation_failure_required_field()
    {
        $timestamp = date('YmdHis');

        $customTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$customTable) {
            $this->markTestSkipped('No uni_table_* found to test Validation.');
        }

        $tableName = $customTable->table_name;

        // ---------------------------------------------------------
        // Step 1 - Send POST to create data with missing required fields via WebAPI
        // ---------------------------------------------------------
        $invalidPayload = [
            'value' => [
                'test_name' => '', // required field empty
                'test_price' => null, // required field null
            ],
        ];

        $response = $this->postJson(
            config('admin.route.prefix', 'admin') . "/webapi/data/{$tableName}",
            $invalidPayload
        );

        // Expect 422 Validation Error or 400 Bad Request
        $this->assertTrue(
            in_array($response->status(), [400, 422]),
            'Expected validation error (400/422) but got: ' . $response->status() . ' Body: ' . $response->content()
        );

        // ---------------------------------------------------------
        // Step 2 - Verify record was NOT created
        // ---------------------------------------------------------
        System::clearCache();
        $latestRecord = $customTable->getValueModel()
            ->newQuery()
            ->orderBy('id', 'desc')
            ->first();

        if ($latestRecord) {
            $this->assertNotEquals('', $latestRecord->getValue('test_name'),
                'Record with empty required field should not be created.');
        }
    }

    /**
     * Test: Soft Delete and Restore data
     */
    public function test_soft_delete_and_restore()
    {
        $timestamp = date('YmdHis');

        $customTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$customTable) {
            $this->markTestSkipped('No uni_table_* found to test Delete/Restore.');
        }

        $tableName = $customTable->table_name;

        // ---------------------------------------------------------
        // Step 1 - Create 1 record to delete
        // ---------------------------------------------------------
        $model = $customTable->getValueModel();
        $model->setValue('test_name', 'Delete Test ' . $timestamp);
        $model->setValue('test_price', 50000);
        $model->save();
        $recordId = $model->id;

        $this->assertNotNull($recordId, 'Record not created.');
        System::clearCache();

        // ---------------------------------------------------------
        // Step 2 - Soft Delete via HTTP DELETE
        // ---------------------------------------------------------
        $response = $this->delete(
            config('admin.route.prefix', 'admin') . "/data/{$tableName}/{$recordId}"
        );

        $this->assertTrue(
            in_array($response->status(), [200, 302]),
            'Delete failed. Status: ' . $response->status()
        );

        System::clearCache();

        // Verify: record has been soft deleted (deleted_at != null)
        $deletedRecord = $customTable->getValueModel()
            ->newQuery()
            ->withTrashed()
            ->find($recordId);
        $this->assertNotNull($deletedRecord, 'Record does not exist after soft delete.');
        $this->assertNotNull($deletedRecord->deleted_at, 'Record not soft deleted (deleted_at is null).');

        // Verify: Record does not appear in normal query
        $normalQuery = $customTable->getValueModel($recordId);
        $this->assertNull($normalQuery, 'Deleted record still appears in normal query.');

        // ---------------------------------------------------------
        // Step 3 - Restore via rowRestore (POST with id param)
        // ---------------------------------------------------------
        $response = $this->post(
            config('admin.route.prefix', 'admin') . "/data/{$tableName}/rowRestore",
            ['id' => $recordId]
        );

        // If rowRestore doesn't work, try GET restoreClick
        if (!in_array($response->status(), [200, 302])) {
            $response = $this->get(
                config('admin.route.prefix', 'admin') . "/data/{$tableName}/{$recordId}/restoreClick"
            );
        }

        $this->assertTrue(
            in_array($response->status(), [200, 302]),
            'Restore failed. Status: ' . $response->status()
        );

        System::clearCache();

        // Verify: record has been restored
        $restoredRecord = $customTable->getValueModel($recordId);
        $this->assertNotNull($restoredRecord, 'Record not restored after restore.');
        $this->assertEquals('Delete Test ' . $timestamp, $restoredRecord->getValue('test_name'));
        $this->assertEquals(50000, $restoredRecord->getValue('test_price'));
    }

    /**
     * Test: Custom View - Create View with Filter and Sort
     */
    public function test_custom_view_with_filter_and_sort()
    {
        $timestamp = date('YmdHis');

        $customTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$customTable) {
            $this->markTestSkipped('No uni_table_* found to test Custom View.');
        }

        $tableName = $customTable->table_name;

        // Create test data for filter
        $model1 = $customTable->getValueModel();
        $model1->setValue('test_name', 'View Filter High ' . $timestamp);
        $model1->setValue('test_price', 500000);
        $model1->save();

        $model2 = $customTable->getValueModel();
        $model2->setValue('test_name', 'View Filter Low ' . $timestamp);
        $model2->setValue('test_price', 10000);
        $model2->save();

        System::clearCache();

        // ---------------------------------------------------------
        // Step 1 - Create Custom View (Model API)
        // ---------------------------------------------------------
        $customView = CustomView::create([
            'custom_table_id' => $customTable->id,
            'view_view_name' => 'Test View ' . $timestamp,
            'view_type' => ViewType::SYSTEM,
            'view_kind_type' => ViewKindType::DEFAULT,
        ]);

        $this->assertNotNull($customView->id, 'Custom View not created.');

        // ---------------------------------------------------------
        // Step 2 - Add View Columns
        // ---------------------------------------------------------
        $testNameCol = CustomColumn::where('custom_table_id', $customTable->id)
            ->where('column_name', 'test_name')
            ->first();
        $testPriceCol = CustomColumn::where('custom_table_id', $customTable->id)
            ->where('column_name', 'test_price')
            ->first();

        if ($testNameCol) {
            CustomViewColumn::create([
                'custom_view_id' => $customView->id,
                'view_column_type' => 0, // Column type
                'view_column_target_id' => $testNameCol->id,
                'order' => 1,
            ]);
        }

        if ($testPriceCol) {
            CustomViewColumn::create([
                'custom_view_id' => $customView->id,
                'view_column_type' => 0,
                'view_column_target_id' => $testPriceCol->id,
                'order' => 2,
            ]);

            // ---------------------------------------------------------
            // Step 3 - Add Filter (test_price >= 100000)
            // ---------------------------------------------------------
            CustomViewFilter::create([
                'custom_view_id' => $customView->id,
                'view_column_type' => 0,
                'view_column_target_id' => $testPriceCol->id,
                'view_filter_condition' => FilterOption::NUMBER_GTE,
                'view_filter_condition_value_text' => '100000',
            ]);

            // ---------------------------------------------------------
            // Step 4 - Add Sort (test_price DESC)
            // ---------------------------------------------------------
            CustomViewSort::create([
                'custom_view_id' => $customView->id,
                'view_column_type' => 0,
                'view_column_target_id' => $testPriceCol->id,
                'sort' => 1, // DESC
                'priority' => 1,
            ]);
        }

        System::clearCache();

        // ---------------------------------------------------------
        // Step 5 - Verify View exists and has filter/sort
        // ---------------------------------------------------------
        $this->assertDatabaseHas('custom_views', [
            'id' => $customView->id,
            'custom_table_id' => $customTable->id,
            'view_view_name' => 'Test View ' . $timestamp,
        ]);

        $filterCount = CustomViewFilter::where('custom_view_id', $customView->id)->count();
        $this->assertGreaterThanOrEqual(1, $filterCount, 'View does not have filter.');

        $sortCount = CustomViewSort::where('custom_view_id', $customView->id)->count();
        $this->assertGreaterThanOrEqual(1, $sortCount, 'View does not have sort.');

        // Verify View page accessible
        $response = $this->get(
            config('admin.route.prefix', 'admin') . "/data/{$tableName}?view_id={$customView->id}"
        );
        $this->assertTrue(
            in_array($response->status(), [200, 302]),
            'View page not accessible. Status: ' . $response->status()
        );
    }

    /**
     * Test: Revision History - Verify revision history
     */
    public function test_revision_history()
    {
        $timestamp = date('YmdHis');

        $customTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if (!$customTable) {
            $this->markTestSkipped('No uni_table_* found to test Revision History.');
        }

        $tableName = $customTable->table_name;

        // ---------------------------------------------------------
        // Step 1 - Create record
        // ---------------------------------------------------------
        $model = $customTable->getValueModel();
        $model->setValue('test_name', 'Revision V1 ' . $timestamp);
        $model->setValue('test_price', 100000);
        $model->save();
        $recordId = $model->id;

        System::clearCache();

        // ---------------------------------------------------------
        // Step 2 - Update 1st time (V2)
        // ---------------------------------------------------------
        $record = $customTable->getValueModel($recordId);
        $record->setValue('test_name', 'Revision V2 ' . $timestamp);
        $record->setValue('test_price', 200000);
        $record->save();

        System::clearCache();

        // ---------------------------------------------------------
        // Step 3 - Update 2nd time (V3)
        // ---------------------------------------------------------
        $record = $customTable->getValueModel($recordId);
        $record->setValue('test_name', 'Revision V3 ' . $timestamp);
        $record->setValue('test_price', 300000);
        $record->save();

        System::clearCache();

        // ---------------------------------------------------------
        // Step 4 - Verify revision history exists
        // ---------------------------------------------------------
        $revisions = DB::table('revisions')
            ->where('revisionable_type', $customTable->table_name)
            ->where('revisionable_id', $recordId)
            ->get();

        $this->assertGreaterThanOrEqual(2, $revisions->count(),
            'Revision history should have at least 2 entries. Found: ' . $revisions->count());

        // Verify final value
        $finalRecord = $customTable->getValueModel($recordId);
        $this->assertEquals('Revision V3 ' . $timestamp, $finalRecord->getValue('test_name'));
        $this->assertEquals(300000, $finalRecord->getValue('test_price'));
    }
}
