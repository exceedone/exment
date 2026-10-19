<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomColumnMulti;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;
use Illuminate\Support\Facades\Schema;

/**
 * Table settings screens that no test reached: the QR/JAN code toggles, the copy and the
 * PATCH/DELETE of a table (admin/table), the calc-formula modal, the column-type option html
 * and the PATCH/DELETE of a column (admin/column/{tableKey}), and the PATCH of a relation
 * (admin/relation/{tableKey}). Every action answers json, plain html or a redirect.
 *
 * Only the copy creates database objects (CREATE TABLE and the index columns of the copy):
 * MySQL commits the test transaction there, so that test removes the copy itself.
 */
class TableSettingsCoverageTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected const COPY_TABLE_NAME = 'claude_cov_copy';

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
        $this->be(LoginUser::find(TestDefine::TESTDATA_USER_LOGINID_ADMIN));
    }

    protected function tearDown(): void
    {
        System::clearCache();
        parent::tearDown();
    }

    // CustomTableController (admin/table) -----------------------------------------

    public function testQrcodeActivateAndDeactivate(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $this->post(admin_url("table/{$table->id}/qrcode_activate"))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.update_succeeded')]);
        $saved = CustomTable::query()->where('id', $table->id)->firstOrFail();
        $this->assertTrue(boolval($saved->getOption('active_qr_flg')));
        $this->assertTrue(boolval($saved->getOption('qr_use')));

        $this->post(admin_url("table/{$table->id}/qrcode_deactivate"))
            ->assertOk()
            ->assertJson(['result' => true]);
        $saved = CustomTable::query()->where('id', $table->id)->firstOrFail();
        $this->assertFalse(boolval($saved->getOption('active_qr_flg')));
        // deactivating keeps the table a QR code table, it only turns the code off
        $this->assertTrue(boolval($saved->getOption('qr_use')));
    }

    public function testJancodeActivateAndDeactivate(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        $this->post(admin_url("table/{$table->id}/jancode_activate"))
            ->assertOk()
            ->assertJson(['result' => true, 'message' => trans('admin.update_succeeded')]);
        $saved = CustomTable::query()->where('id', $table->id)->firstOrFail();
        $this->assertTrue(boolval($saved->getOption('active_jan_flg')));
        $this->assertTrue(boolval($saved->getOption('jan_use')));

        $this->post(admin_url("table/{$table->id}/jancode_deactivate"))
            ->assertOk()
            ->assertJson(['result' => true]);
        $saved = CustomTable::query()->where('id', $table->id)->firstOrFail();
        $this->assertFalse(boolval($saved->getOption('active_jan_flg')));
        $this->assertTrue(boolval($saved->getOption('jan_use')));
    }

    public function testTablePatch(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);

        // only the posted fields are written (the table name is display only on edit)
        $this->patch(admin_url("table/{$table->id}"), [
            'table_view_name' => 'custom_value_edit (claude patch)',
            'description' => 'patched by the coverage test',
        ])->assertRedirect(admin_url('table'))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('custom_tables', [
            'id' => $table->id,
            'table_name' => TestDefine::TESTDATA_TABLE_NAME_EDIT,
            'table_view_name' => 'custom_value_edit (claude patch)',
            'description' => 'patched by the coverage test',
        ]);
    }

    public function testTableCopyAndDelete(): void
    {
        $source = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT);
        $sourceColumnNames = $source->custom_columns()->pluck('column_name')->sort()->values()->all();

        $this->post(admin_url("table/{$source->id}/copy"), [
            'table_name' => self::COPY_TABLE_NAME,
            'table_view_name' => 'Claude coverage copy',
            'include_view_flg' => '0',
            'include_form_flg' => '0',
        ])->assertOk()->assertJson(['result' => true, 'redirect' => admin_url('table')]);

        $copy = CustomTable::query()->where('table_name', self::COPY_TABLE_NAME)->first();
        $this->assertNotNull($copy, 'the copy is saved as a new table');
        $copyId = $copy->id;
        $dbTableName = getDBTableName($copy);

        try {
            $this->assertSame('Claude coverage copy', $copy->table_view_name);
            $this->assertSame($sourceColumnNames, $copy->custom_columns()->pluck('column_name')->sort()->values()->all());
            $this->assertTrue(Schema::hasTable($dbTableName), 'the copy gets its own value table');

            $this->delete(admin_url("table/{$copyId}"))
                ->assertOk()
                ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);

            $this->assertDatabaseMissing('custom_tables', ['id' => $copyId]);
            $this->assertDatabaseMissing('custom_columns', ['custom_table_id' => $copyId]);
            $this->assertFalse(Schema::hasTable($dbTableName), 'deleting the table drops its value table');
        } finally {
            // The CREATE TABLE above ended the test transaction, so nothing is rolled back:
            // remove what is left. Deleting a table keeps its column multi settings (as in v6).
            $left = CustomTable::query()->where('id', $copyId)->first();
            if (isset($left)) {
                $left->delete();
            }
            CustomColumnMulti::query()->where('custom_table_id', $copyId)->delete();
        }
    }

    // CustomColumnController (admin/column/{tableKey}) ----------------------------

    public function testCalcModalListsTheCalcColumnsOfTheTable(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $response = $this->post(admin_url("column/{$tableKey}/calcModal"), [
            'options_calc_formula' => 'claude-calc-formula',
        ])->assertOk()->assertJson([
            'result' => true,
            'contentname' => 'options_calc_formula',
            'title' => exmtrans('custom_column.options.calc_formula'),
        ]);

        $body = (string) $response->json('body');
        $this->assertStringContainsString('claude-calc-formula', $body, 'the posted formula is shown for editing');
        $this->assertStringContainsString('data-val="${value:integer}"', $body);
        $this->assertStringContainsString('data-val="${value:decimal}"', $body);
    }

    public function testCalcModalOfAColumnLeavesThatColumnOut(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $integer = CustomColumn::getEloquent('integer', $tableKey);

        $response = $this->post(admin_url("column/{$tableKey}/{$integer->id}/calcModal"), [
            'options_calc_formula' => '',
        ])->assertOk()->assertJson(['result' => true, 'contentname' => 'options_calc_formula']);

        // a formula cannot use the column it belongs to
        $body = (string) $response->json('body');
        $this->assertStringNotContainsString('data-val="${value:integer}"', $body);
        $this->assertStringContainsString('data-val="${value:decimal}"', $body);
    }

    public function testColumnTypeHtmlRendersTheOptionsOfTheChosenType(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;

        $response = $this->get(admin_url("column/{$tableKey}/columnTypeHtml") . '?val=text&form_type=option&form_uniqueName=claude_cov_form')
            ->assertOk()
            ->assertJsonStructure(['body', 'script']);

        $this->assertStringContainsString('string_length', (string) $response->json('body'));
    }

    public function testColumnTypeHtmlOfAColumn(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        $integer = CustomColumn::getEloquent('integer', $tableKey);

        $response = $this->get(admin_url("column/{$tableKey}/{$integer->id}/columnTypeHtml") . '?val=integer&form_type=option&form_uniqueName=claude_cov_form')
            ->assertOk()
            ->assertJsonStructure(['body', 'script']);

        $this->assertStringContainsString('updown_button', (string) $response->json('body'));
    }

    public function testColumnPatch(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_ALL_COLUMNS_FORTEST;
        // a column without an index: renaming it changes no database column
        $textarea = CustomColumn::getEloquent('textarea', $tableKey);

        $this->patch(admin_url("column/{$tableKey}/{$textarea->id}"), [
            'column_view_name' => 'textarea (claude patch)',
        ])->assertRedirect(admin_url("column/{$tableKey}"))->assertSessionHasNoErrors();

        $this->assertDatabaseHas('custom_columns', [
            'id' => $textarea->id,
            'column_name' => 'textarea',
            'column_view_name' => 'textarea (claude patch)',
        ]);
    }

    public function testColumnDelete(): void
    {
        $tableKey = TestDefine::TESTDATA_TABLE_NAME_EDIT;
        $table = CustomTable::getEloquent($tableKey);
        // without an index, so neither the save nor the delete touches the database schema
        $column = CustomColumn::create([
            'custom_table_id' => $table->id,
            'column_name' => 'claude_cov_delete',
            'column_view_name' => 'claude_cov_delete',
            'column_type' => ColumnType::TEXT,
            'options' => [],
        ]);

        $this->delete(admin_url("column/{$tableKey}/{$column->id}"))
            ->assertOk()
            ->assertJson(['status' => true, 'message' => trans('admin.delete_succeeded')]);

        $this->assertDatabaseMissing('custom_columns', ['id' => $column->id]);
    }

    // CustomRelationController (admin/relation/{tableKey}) -------------------------

    public function testRelationPatch(): void
    {
        $parent = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $relation = CustomRelation::query()
            ->where('parent_custom_table_id', $parent->id)
            ->where('relation_type', RelationType::ONE_TO_MANY)
            ->first();
        $this->assertNotNull($relation, 'seed data has a one-to-many relation of ' . TestDefine::TESTDATA_TABLE_NAME_PARENT_TABLE);
        $indexText = CustomColumn::getEloquent('index_text', $parent);

        $this->patch(admin_url("relation/{$parent->table_name}/{$relation->id}"), [
            'options' => [
                'parent_import_column_id' => $indexText->id,
                'parent_export_column_id' => $indexText->id,
            ],
        ])->assertRedirect(admin_url("relation/{$parent->table_name}"))->assertSessionHasNoErrors();

        $saved = CustomRelation::query()->where('id', $relation->id)->firstOrFail();
        $this->assertEquals($indexText->id, $saved->getOption('parent_import_column_id'));
        $this->assertEquals($indexText->id, $saved->getOption('parent_export_column_id'));
        $this->assertEquals(RelationType::ONE_TO_MANY, $saved->relation_type, 'the relation type is display only on edit');
    }
}
