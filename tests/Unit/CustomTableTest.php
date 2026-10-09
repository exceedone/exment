<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Enums\MultisettingType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;
use Exceedone\Exment\Tests\TestDefine;
use Exceedone\Exment\Enums\SystemColumn;

class CustomTableTest extends UnitTestBase
{
    /**
     * @return void
     */
    public function testFuncGetMatchedCustomValues1()
    {
        $info = CustomTable::getEloquent('information');

        $keys = ["1","3","5"];
        $values = $info->getMatchedCustomValues($keys);

        foreach ($keys as $key) {
            $this->assertTrue(array_has($values, $key));

            $value = array_get($values, $key);
            $this->assertTrue(array_get($value, 'id') == $key);
        }

        foreach (["2", "4"] as $key) {
            $this->assertTrue(!array_has($values, $key));
        }
    }

    /**
     * @return void
     */
    public function testFuncGetMatchedCustomValues2()
    {
        $info = CustomTable::getEloquent('information');

        $keys = ['3'];
        $values = $info->getMatchedCustomValues($keys, 'value.priority');

        foreach ($keys as $key) {
            $this->assertTrue(array_has($values, $key));

            $value = array_get($values, $key);
            $this->assertTrue(array_get($value, 'value.priority') == $key);
        }

        foreach (['2', '4'] as $key) {
            $this->assertTrue(!array_has($values, $key));
        }
    }

    /**
     * @return void
     */
    public function testFuncCopyCustomTable()
    {
        $from_table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST);
        $response = $from_table->copyTable([
            'table_name' => 'copy_table',
            'table_view_name' => 'コピーテーブル',
        ]);
        $this->assertTrue(is_array($response));
        $this->assertTrue(array_get($response, 'result'));

        // compare custom table
        $to_table = CustomTable::getEloquent('copy_table');
        $this->assertTrue($to_table instanceof CustomTable);
        $diff = collect($to_table->getAttributes())->diffAssoc(collect($from_table->getAttributes()));
        foreach ($diff as $key => $value) {
            switch ($key) {
                case 'table_name':
                    $this->assertEquals($value, 'copy_table');
                    break;
                case 'table_view_name':
                    $this->assertEquals($value, 'コピーテーブル');
                    break;
                default:
                    $option = SystemColumn::getOption(['sqlname' => $key]);
                    $this->assertTrue(is_array($option));
                    break;
            }
        }

        // compare custom column
        $this->assertEquals($to_table->custom_columns_cache->count(), $from_table->custom_columns_cache->count());
        foreach ($to_table->custom_columns_cache as $to_column) {
            $from_column = $from_table->custom_columns_cache->filter(function($column) use($to_column){
                return $column->column_name == $to_column->column_name;
            })->first();
            $diff = collect($to_column->getAttributes())->diffAssoc(collect($from_column->getAttributes()));
            foreach ($diff as $key => $value) {
                switch ($key) {
                    case 'custom_table_id':
                        $this->assertEquals($value, $to_table->id);
                        break;
                    default:
                        $option = SystemColumn::getOption(['sqlname' => $key]);
                        $this->assertTrue(is_array($option));
                        break;
                }
            }
        }

        // compare custom column multisettings
        $this->assertEquals($to_table->custom_column_multisettings->count(), $from_table->custom_column_multisettings->count());
        foreach ($to_table->custom_column_multisettings as $to_column) {
            $from_column = $from_table->custom_column_multisettings->filter(function($column) use($to_column){
                return $column->multisetting_type == $to_column->multisetting_type &&
                    $column->priority == $to_column->priority;
            })->first();
            $this->assertEquals($to_column->custom_table_id, $to_table->id);
            foreach ($from_column->options as $key => $value) {
                $to_value = array_get($to_column->options, $key);
                switch ($key) {
                    case 'table_label_id':
                    case 'unique1_id':
                    case 'unique2_id':
                    case 'unique3_id':
                    case 'share_column_id':
                    case 'compare_column1_id':
                    case 'compare_column2_id':
                        $from = $from_table->custom_columns_cache->filter(function($column) use($value){
                            return $column->id == $value;
                        })->first();
                        $to = $to_table->custom_columns_cache->filter(function($column) use($to_value){
                            return $column->id == $to_value;
                        })->first();

                        $this->assertEquals($from->column_name, $to->column_name);
                        break;
                    default:
                        $this->assertEquals($value, $to_value);
                        break;
                }
            }
        }
        $to_table->delete();
    }

    /**
     * Test that barcode (QR code / JAN code) settings are excluded when copying a custom table.
     *
     * @return void
     */
    public function testCopyTableExcludesBarcodeSettings()
    {
        $from_table = CustomTable::getEloquent('information');

        // Set barcode-related options on the source table
        $barcodeOptions = [
            // QR code settings
            'active_qr_flg' => true,
            'qr_use' => true,
            'text_qr' => 'TEST-QR',
            'refer_column' => 'id',
            'cell_width' => 62,
            'cell_height' => 31,
            'margin_left' => 9,
            'margin_top' => 9,
            'col_per_page' => 3,
            'row_per_page' => 9,
            'col_spacing' => 3,
            'row_spacing' => 0,
            'form_after_read' => 999,       // fake form ID from old table
            'action_after_read' => 'top',
            // JAN code settings
            'active_jan_flg' => true,
            'jan_use' => true,
            'form_after_create_jan_code' => 888,  // fake form ID from old table
            'action_after_create_jan_code' => 'top',
            'form_after_read_jan_code' => 777,    // fake form ID from old table
            'action_after_read_jan_code' => 'top',
        ];

        foreach ($barcodeOptions as $key => $value) {
            $from_table->setOption($key, $value);
        }
        $from_table->save();

        // Verify source table has barcode options
        $from_table = CustomTable::getEloquent($from_table->id);
        $this->assertEquals(true, $from_table->getOption('active_qr_flg'));
        $this->assertEquals('TEST-QR', $from_table->getOption('text_qr'));
        $this->assertEquals(true, $from_table->getOption('active_jan_flg'));

        // Copy the table
        $response = $from_table->copyTable([
            'table_name' => 'copy_barcode_test',
            'table_view_name' => 'Copy Barcode Test',
        ]);
        $this->assertTrue(is_array($response));
        $this->assertTrue(array_get($response, 'result'));

        // Get copied table
        $to_table = CustomTable::getEloquent('copy_barcode_test');
        $this->assertInstanceOf(CustomTable::class, $to_table);

        // Assert all barcode-related options are NOT copied
        foreach (array_keys($barcodeOptions) as $key) {
            $this->assertNull(
                $to_table->getOption($key),
                "Barcode option '{$key}' should not be copied to new table, but found value: " . json_encode($to_table->getOption($key))
            );
        }

        // Assert non-barcode options are still copied
        $this->assertNotNull($to_table->getOption('search_enabled') ?? $to_table->getOption('table_label_format') ?? true,
            "Non-barcode options should still be present");

        // Clean up
        $to_table->delete();

        // Clean up source table barcode options
        foreach (array_keys($barcodeOptions) as $key) {
            $from_table->forgetOption($key);
        }
        $from_table->save();
    }

    /**
     * Test that barcode settings with old table's form IDs are not carried over
     * (form_after_read, form_after_create_jan_code, form_after_read_jan_code reference old table's form IDs)
     *
     * @return void
     */
    public function testCopyTableBarcodeFormIdsNotCarriedOver()
    {
        $from_table = CustomTable::getEloquent('information');

        // Get a real form ID from the source table
        $source_form = CustomForm::where('custom_table_id', $from_table->id)->first();
        if (!$source_form) {
            $this->markTestSkipped('No forms found for source table');
        }

        // Set form references pointing to old table's forms
        $from_table->setOption('active_qr_flg', true);
        $from_table->setOption('form_after_read', $source_form->id);
        $from_table->setOption('active_jan_flg', true);
        $from_table->setOption('form_after_create_jan_code', $source_form->id);
        $from_table->setOption('form_after_read_jan_code', $source_form->id);
        $from_table->save();

        // Copy the table
        $response = $from_table->copyTable([
            'table_name' => 'copy_barcode_form_test',
            'table_view_name' => 'Copy Barcode Form Test',
        ]);
        $this->assertTrue(array_get($response, 'result'));

        $to_table = CustomTable::getEloquent('copy_barcode_form_test');
        $this->assertInstanceOf(CustomTable::class, $to_table);

        // Ensure the copied table does NOT have form references from old table
        $this->assertNull($to_table->getOption('form_after_read'),
            "form_after_read should not reference old table's form ID");
        $this->assertNull($to_table->getOption('form_after_create_jan_code'),
            "form_after_create_jan_code should not reference old table's form ID");
        $this->assertNull($to_table->getOption('form_after_read_jan_code'),
            "form_after_read_jan_code should not reference old table's form ID");

        // Clean up
        $to_table->delete();

        // Clean up source table barcode options
        foreach (['active_qr_flg', 'form_after_read', 'active_jan_flg',
                   'form_after_create_jan_code', 'form_after_read_jan_code'] as $key) {
            $from_table->forgetOption($key);
        }
        $from_table->save();
    }

    /**
     * Export/Import template should restore column-based table settings
     * using column names, not old IDs from source environment.
     *
     * @return void
     */
    public function testTemplateExportImportRemapsColumnSettingsByName()
    {
        $sourceBaseTable = CustomTable::getEloquent('information');
        $this->assertInstanceOf(CustomTable::class, $sourceBaseTable);

        $tableName = 'tmpl_col_' . substr(md5(uniqid('', true)), 0, 8);
        $copyResult = $sourceBaseTable->copyTable([
            'table_name' => $tableName,
            'table_view_name' => 'Template Column Mapping',
        ]);
        $this->assertTrue(array_get($copyResult, 'result'));

        $sourceTable = CustomTable::getEloquent($tableName);
        $this->assertInstanceOf(CustomTable::class, $sourceTable);

        $sourceColumn = $sourceTable->custom_columns_cache
            ->first(function ($column) {
                return !boolval($column->system_flg);
            });
        $this->assertNotNull($sourceColumn);

        $setting = new CustomColumnMulti();
        $setting->custom_table_id = $sourceTable->id;
        $setting->multisetting_type = MultisettingType::MULTI_UNIQUES;
        $setting->priority = 999;
        $setting->setOption('unique1_id', $sourceColumn->id);
        $setting->save();

        $export = $this->createTableOnlyTemplateExportData($tableName);

        $sourceColumnId = $sourceColumn->id;
        $sourceColumnName = $sourceColumn->column_name;

        // Simulate import into another environment where IDs differ.
        $sourceTable->delete();

        $importer = new TemplateImporter();
        $importer->import($export);

        $importedTable = CustomTable::getEloquent($tableName);
        $this->assertInstanceOf(CustomTable::class, $importedTable);

        $importedSetting = $importedTable->custom_column_multisettings
            ->first(function ($item) {
                return $item->multisetting_type == MultisettingType::MULTI_UNIQUES
                    && $item->priority == 999;
            });
        $this->assertNotNull($importedSetting);

        $importedColumnId = $importedSetting->getOption('unique1_id');
        $this->assertNotNull($importedColumnId);

        $importedColumn = $importedTable->custom_columns_cache
            ->first(function ($column) use ($importedColumnId) {
                return strval($column->id) === strval($importedColumnId);
            });
        $this->assertNotNull($importedColumn);
        $this->assertEquals($sourceColumnName, $importedColumn->column_name);
        $this->assertNotEquals(strval($sourceColumnId), strval($importedColumnId));

        $importedTable->delete();
    }

    /**
     * Export/Import template should restore form-column settings
     * using column names, not old IDs from source environment.
     *
     * @return void
     */
    public function testTemplateExportImportRemapsFormSettingsByName()
    {
        $sourceBaseTable = CustomTable::getEloquent('information');
        $this->assertInstanceOf(CustomTable::class, $sourceBaseTable);

        $tableName = 'tmpl_form_' . substr(md5(uniqid('', true)), 0, 8);
        $copyResult = $sourceBaseTable->copyTable([
            'table_name' => $tableName,
            'table_view_name' => 'Template Form Mapping',
        ]);
        $this->assertTrue(array_get($copyResult, 'result'));

        $sourceTable = CustomTable::getEloquent($tableName);
        $this->assertInstanceOf(CustomTable::class, $sourceTable);

        $sourceForm = CustomForm::where('custom_table_id', $sourceTable->id)
            ->with('custom_form_blocks.custom_form_columns.custom_column')
            ->first();
        if (!$sourceForm) {
            $sourceForm = CustomForm::getDefault($sourceTable);
            $sourceForm = CustomForm::where('id', $sourceForm->id)
                ->with('custom_form_blocks.custom_form_columns.custom_column')
                ->first();
        }
        $this->assertNotNull($sourceForm);

        $sourceFormColumn = collect($sourceForm->custom_form_blocks)
            ->flatMap(function ($block) {
                /** @var \Illuminate\Support\Collection<int, \Exceedone\Exment\Model\CustomFormColumn> $columns */
                $columns = $block->custom_form_columns;
                return $columns;
            })
            ->first(function ($formColumn) {
                return $formColumn->form_column_type == FormColumnType::COLUMN && isset($formColumn->custom_column);
            });
        $this->assertNotNull($sourceFormColumn);

        $sourceTargetColumnId = $sourceFormColumn->form_column_target_id;
        $sourceTargetColumnName = $sourceFormColumn->custom_column->column_name;

        $export = $this->createTableOnlyTemplateExportData($tableName);

        // Simulate import into another environment where IDs differ.
        $sourceTable->delete();

        $importer = new TemplateImporter();
        $importer->import($export);

        $importedTable = CustomTable::getEloquent($tableName);
        $this->assertInstanceOf(CustomTable::class, $importedTable);

        $importedForm = CustomForm::where('custom_table_id', $importedTable->id)
            ->where('suuid', $sourceForm->suuid)
            ->with('custom_form_blocks.custom_form_columns.custom_column')
            ->first();
        $this->assertNotNull($importedForm);

        $importedFormColumn = collect($importedForm->custom_form_blocks)
            ->flatMap(function ($block) {
                /** @var \Illuminate\Support\Collection<int, \Exceedone\Exment\Model\CustomFormColumn> $columns */
                $columns = $block->custom_form_columns;
                return $columns;
            })
            ->first(function ($formColumn) use ($sourceFormColumn) {
                return $formColumn->suuid == $sourceFormColumn->suuid;
            });
        $this->assertNotNull($importedFormColumn);

        $importedTargetColumnId = $importedFormColumn->form_column_target_id;
        $this->assertNotNull($importedTargetColumnId);
        $this->assertNotNull($importedFormColumn->custom_column);
        $this->assertEquals($sourceTargetColumnName, $importedFormColumn->custom_column->column_name);
        $this->assertNotEquals(strval($sourceTargetColumnId), strval($importedTargetColumnId));

        $importedTable->delete();
    }

    /**
     * Export/Import template should keep table-level QR/JAN settings valid
     * by remapping form references to forms in the imported table.
     *
     * @return void
     */
    public function testTemplateExportImportRemapsTableBarcodeFormSettings()
    {
        $sourceBaseTable = CustomTable::getEloquent('information');
        $this->assertInstanceOf(CustomTable::class, $sourceBaseTable);

        $tableName = 'tmpl_bar_' . substr(md5(uniqid('', true)), 0, 8);
        $copyResult = $sourceBaseTable->copyTable([
            'table_name' => $tableName,
            'table_view_name' => 'Template Barcode Mapping',
        ]);
        $this->assertTrue(array_get($copyResult, 'result'));

        $sourceTable = CustomTable::getEloquent($tableName);
        $this->assertInstanceOf(CustomTable::class, $sourceTable);

        $sourceForm = CustomForm::getDefault($sourceTable);
        $sourceForm = CustomForm::getEloquent($sourceForm->id);
        $this->assertNotNull($sourceForm);

        $sourceColumn = $sourceTable->custom_columns_cache
            ->first(function ($column) {
                return !boolval($column->system_flg);
            });
        $this->assertNotNull($sourceColumn);

        // Use the numeric column ID, which is what the UI stores in refer_column.
        $sourceTable->setOption('active_qr_flg', true);
        $sourceTable->setOption('refer_column', strval($sourceColumn->id));
        $sourceTable->setOption('form_after_read', $sourceForm->id);
        $sourceTable->setOption('active_jan_flg', true);
        $sourceTable->setOption('form_after_create_jan_code', $sourceForm->id);
        $sourceTable->setOption('form_after_read_jan_code', $sourceForm->id);
        $sourceTable->save();

        $export = $this->createTableOnlyTemplateExportData($tableName);

        // Verify the export contains the helper column name alongside the numeric ID.
        $exportedTableJson = $export['custom_tables'][0];
        $this->assertEquals($sourceColumn->column_name, array_get($exportedTableJson, 'options.refer_column_name'),
            'Export should include refer_column_name as a helper field');

        $sourceColumnName = $sourceColumn->column_name;
        $sourceTable->delete();

        $importer = new TemplateImporter();
        $importer->import($export);

        $importedTable = CustomTable::getEloquent($tableName);
        $this->assertInstanceOf(CustomTable::class, $importedTable);

        // After import, refer_column should be the new column's numeric ID (not the old ID),
        // and refer_column_name must NOT be persisted as an option.
        $this->assertNull($importedTable->getOption('refer_column_name'),
            'refer_column_name helper field must not be persisted after import');

        $importedColumn = $importedTable->custom_columns_cache
            ->first(function ($column) use ($sourceColumnName) {
                return $column->column_name === $sourceColumnName;
            });
        $this->assertNotNull($importedColumn, "Imported table should have a column named '{$sourceColumnName}'");
        $this->assertEquals(
            strval($importedColumn->id),
            strval($importedTable->getOption('refer_column')),
            'refer_column should be remapped to the correct column ID in the imported environment'
        );

        $importedFormIds = CustomForm::where('custom_table_id', $importedTable->id)
            ->pluck('id')
            ->map(function ($id) {
                return strval($id);
            })
            ->toArray();
        $this->assertNotEmpty($importedFormIds);

        foreach (['form_after_read', 'form_after_create_jan_code', 'form_after_read_jan_code'] as $optionKey) {
            $optionValue = $importedTable->getOption($optionKey);
            $this->assertNotNull($optionValue, "{$optionKey} should be set after template import");
            $this->assertContains(
                strval($optionValue),
                $importedFormIds,
                "{$optionKey} should reference a form id in imported table"
            );
        }

        $importedTable->delete();
    }

    /**
     * Build minimal template export payload for a single table.
     *
     * @param string $tableName
     * @return array<string, mixed>
     */
    private function createTableOnlyTemplateExportData($tableName)
    {
        $table = CustomTable::where('table_name', $tableName)
            ->with(['custom_columns', 'custom_column_multisettings'])
            ->first();

        $forms = CustomForm::where('custom_table_id', $table->id)
            ->with('custom_form_blocks.custom_form_columns.custom_column')
            ->get();

        return [
            'custom_tables' => [$table->getTemplateExportItems()],
            'custom_relations' => [],
            'custom_forms' => $forms->map(function ($form) {
                return $form->getTemplateExportItems();
            })->values()->toArray(),
            'custom_views' => [],
            'custom_copies' => [],
        ];
    }
}
