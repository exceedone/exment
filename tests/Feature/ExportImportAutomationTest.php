<?php

namespace Tests\Feature;

use Tests\TestCase;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Illuminate\Http\UploadedFile;

class ExportImportAutomationTest extends TestCase
{
    protected $adminLoginUser;
    protected $customTable;
    protected $tableName;

    protected function setUp(): void
    {
        parent::setUp();

        $this->adminLoginUser = LoginUser::find(1) ?? LoginUser::first();
        if (!$this->adminLoginUser) {
            $this->markTestSkipped('Admin User not found for setup.');
        }

        System::clearCache();

        // ---------------------------------------------------------
        // Setup: Reuse the test table from previous E2E steps
        // ---------------------------------------------------------
        $reusedTable = CustomTable::where('table_name', 'like', 'uni_table_%')
            ->orderBy('id', 'desc')
            ->first();

        if ($reusedTable) {
            $this->customTable = $reusedTable;
            $this->tableName = $reusedTable->table_name;
        } else {
            $this->markTestSkipped('No common table uni_table_* found to test Export/Import. Run Core Data test first.');
        }
    }

    public function test_export_data_to_excel()
    {
        $this->actingAs($this->adminLoginUser, 'admin');
        \Exment::setGuard('admin');

        // ---------------------------------------------------------
        // Step 1 - Export Data (HTTP GET with action=export)
        // ---------------------------------------------------------
        // Simulate clicking the "Export" button on the Data list page
        $response = $this->get(
            config('admin.route.prefix', 'admin') . "/data/{$this->tableName}?action=export"
        );

        // Assert export returns successfully (either stream download 200 or redirect to job 302)
        $this->assertTrue(
            in_array($response->status(), [200, 302]),
            'Export request failed. Status: ' . $response->status()
        );
    }
    
    public function test_import_data_from_csv()
    {
        $this->actingAs($this->adminLoginUser, 'admin');
        \Exment::setGuard('admin');
        
        $timestamp = date('YmdHis');

        // ---------------------------------------------------------
        // Step 2 - Access Import Modal
        // ---------------------------------------------------------
        $modalResponse = $this->get(
            config('admin.route.prefix', 'admin') . "/data/{$this->tableName}/importModal"
        );
        $modalResponse->assertStatus(200);

        // ---------------------------------------------------------
        // Step 3 - Prepare CSV File for Import from Fixture
        // ---------------------------------------------------------
        $csvPath = base_path('tests/Feature/Fixtures/import_data.csv');
        $file = new UploadedFile(
            $csvPath,
            'import_data.csv',
            'text/csv',
            null,
            true // test mode
        );

        $customTable = CustomTable::getEloquent($this->tableName);
        $initialCount = $customTable->getValueModel()->get()->filter(function($record) {
            return strpos($record->getValue('test_name'), 'Imported Product ') === 0;
        })->count();

        $payload = [
            'custom_table_file' => $file,
            'import_format' => 'csv',
            'encoding' => 'UTF-8',
            'header_row' => 1,
            'data_start_row' => 3,
            'import_type' => '1', // 1: Insert, 2: Update, 3: Insert & Update
            'select_primary_key' => 'id',
        ];

        // ---------------------------------------------------------
        // Step 4 - Submit Import POST Request
        // ---------------------------------------------------------
        $response = $this->post(
            config('admin.route.prefix', 'admin') . "/data/{$this->tableName}/import",
            $payload
        );

        // Assert import request is accepted
        $this->assertTrue(
            in_array($response->status(), [200, 302]),
            'Import submit failed. Status: ' . $response->status()
        );

        System::clearCache();

        // ---------------------------------------------------------
        // Step 5 - Verify Data is Imported (Exactly 10 items)
        // ---------------------------------------------------------
        $customTable = CustomTable::getEloquent($this->tableName);
        $finalCount = $customTable->getValueModel()->get()->filter(function($record) {
            return strpos($record->getValue('test_name'), 'Imported Product ') === 0;
        })->count();

        $this->assertEquals($initialCount + 10, $finalCount, 'Import did not insert exactly 10 records.');
    }
}
