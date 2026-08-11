<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\ColumnType;
use Exceedone\Exment\Enums\ConditionType;
use Exceedone\Exment\Enums\FormColumnType;
use Exceedone\Exment\Enums\RelationType;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomForm;
use Exceedone\Exment\Model\CustomFormColumn;
use Exceedone\Exment\Model\CustomRelation;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\Model\CustomViewColumn;
use Exceedone\Exment\Services\TemplateImportExport\TemplateImporter;

/**
 * Tests for TemplateImporter "sync_deleted_columns" import option:
 * columns existing in DB but not contained in the imported template are deleted
 * (data included), scoped per table contained in the template.
 *
 * NOT using DatabaseTransactions: template import creates the physical value table
 * (DDL causes implicit commit on MySQL and would break the wrapping transaction).
 * The test tables are created and deleted explicitly per test instead.
 */
class TemplateImportSyncDeletedColumnsTest extends UnitTestBase
{
    protected const TABLE_NAME = 'unittest_syncdel_cols';
    protected const PARENT_TABLE_NAME = 'unittest_syncdel_parent';
    protected const CHILD_TABLE_NAME = 'unittest_syncdel_child';

    protected function setUp(): void
    {
        parent::setUp();
        $this->deleteTestTables();
    }

    protected function tearDown(): void
    {
        $this->deleteTestTables();
        parent::tearDown();
    }

    protected function deleteTestTables()
    {
        // child first: it may reference the parent table
        foreach ([static::CHILD_TABLE_NAME, static::PARENT_TABLE_NAME, static::TABLE_NAME] as $tableName) {
            $custom_table = CustomTable::where('table_name', $tableName)->first();
            if (isset($custom_table)) {
                $custom_table->delete();
            }
        }
    }

    /**
     * Build one custom_tables entry.
     * $columns items are either 'column_name' or 'column_name' => [options].
     */
    protected function tableEntry(string $tableName, array $columns): array
    {
        return [
            'table_name' => $tableName,
            'table_view_name' => $tableName,
            'custom_columns' => collect($columns)->map(function ($options, $columnName) {
                if (is_int($columnName)) {
                    $columnName = $options;
                    $options = [];
                }
                return [
                    'column_name' => $columnName,
                    'column_view_name' => $columnName,
                    'column_type' => ColumnType::TEXT,
                    'options' => $options,
                ];
            })->values()->toArray(),
        ];
    }

    /**
     * Build minimal template json for the default test table containing $columns.
     */
    protected function templateJson(array $columns): array
    {
        return ['custom_tables' => [$this->tableEntry(static::TABLE_NAME, $columns)]];
    }

    /**
     * Create the default test table with col_keep and col_del via normal template import.
     */
    protected function createTestTable(): CustomTable
    {
        (new TemplateImporter())->import($this->templateJson(['col_keep', 'col_del']));

        /** @var CustomTable $custom_table */
        $custom_table = CustomTable::getEloquent(static::TABLE_NAME);
        return $custom_table;
    }

    protected function columnNamesOf(string $tableName): array
    {
        $custom_table = CustomTable::where('table_name', $tableName)->first();
        return CustomColumn::where('custom_table_id', $custom_table->id)
            ->pluck('column_name')->sort()->values()->toArray();
    }

    protected function columnNames(): array
    {
        return $this->columnNamesOf(static::TABLE_NAME);
    }

    protected function formColumnCount(CustomColumn $custom_column): int
    {
        return CustomFormColumn::where('form_column_target_id', $custom_column->id)
            ->where('form_column_type', FormColumnType::COLUMN)->count();
    }

    protected function viewColumnCount(CustomColumn $custom_column): int
    {
        return CustomViewColumn::where('view_column_target_id', $custom_column->id)
            ->where('view_column_type', ConditionType::COLUMN)->count();
    }

    public function testImportWithoutOptionKeepsColumns()
    {
        $this->createTestTable();

        (new TemplateImporter())->import($this->templateJson(['col_keep']));

        $this->assertSame(
            ['col_del', 'col_keep'],
            $this->columnNames(),
            'default import must stay additive: col_del must survive without sync_deleted_columns'
        );
    }

    public function testImportWithOptionDeletesColumnAndData()
    {
        $custom_table = $this->createTestTable();

        $model = $custom_table->getValueModel();
        $model->setValue(['col_keep' => 'AAA', 'col_del' => 'BBB']);
        $model->save();
        $id = $model->id;

        $importer = new TemplateImporter();
        $importer->import($this->templateJson(['col_keep']), false, false, false, ['sync_deleted_columns' => true]);

        $this->assertSame(['col_keep'], $this->columnNames());
        $this->assertSame([static::TABLE_NAME . '.col_del'], $importer->syncDeletedColumns);

        $value = CustomTable::getEloquent(static::TABLE_NAME)->getValueModel()->find($id)->value;
        $this->assertSame('AAA', array_get($value, 'col_keep'));
        $this->assertArrayNotHasKey('col_del', $value, 'deleted column value must be removed from stored records');
    }

    public function testEmptyOrMissingColumnListNeverDeletes()
    {
        $this->createTestTable();
        $importer = new TemplateImporter();

        // empty custom_columns: a column-less/stub table definition must not wipe the target table
        $importer->deleteColumnsNotContainedInTemplate($this->templateJson([]));
        $this->assertSame(['col_del', 'col_keep'], $this->columnNames());

        // missing custom_columns key
        $importer->deleteColumnsNotContainedInTemplate([
            'custom_tables' => [['table_name' => static::TABLE_NAME]],
        ]);
        $this->assertSame(['col_del', 'col_keep'], $this->columnNames());

        // table not existing in this system: must not throw
        $importer->deleteColumnsNotContainedInTemplate([
            'custom_tables' => [[
                'table_name' => 'unittest_syncdel_missing',
                'custom_columns' => [['column_name' => 'foo']],
            ]],
        ]);

        $this->assertSame([], $importer->syncDeletedColumns);
    }

    public function testDisabledDeleteColumnSurvives()
    {
        $this->createTestTable();

        // make col_del delete-protected (disabled_delete is based on system_flg)
        $custom_column = CustomColumn::getEloquent('col_del', CustomTable::getEloquent(static::TABLE_NAME));
        $custom_column->system_flg = true;
        $custom_column->save();

        $importer = new TemplateImporter();
        $importer->deleteColumnsNotContainedInTemplate($this->templateJson(['col_keep']));

        $this->assertSame(['col_del', 'col_keep'], $this->columnNames());
        $this->assertSame([], $importer->syncDeletedColumns);
    }

    /**
     * Deleting a column must also clean its references in forms and views
     * (deletingChildren), while the form/view and other columns' references survive.
     */
    public function testDeletingColumnCleansFormAndViewReferences()
    {
        $custom_table = $this->createTestTable();
        $col_del = CustomColumn::getEloquent('col_del', $custom_table);
        $col_keep = CustomColumn::getEloquent('col_keep', $custom_table);

        // default form contains every custom column as a form column
        CustomForm::getDefault($custom_table);
        $this->assertGreaterThan(0, $this->formColumnCount($col_del), 'precondition: col_del must be placed on the form');

        // view containing col_del
        $custom_view = CustomView::getAllData($custom_table);
        $view_column = new CustomViewColumn();
        $view_column->custom_view_id = $custom_view->id;
        $view_column->view_column_type = ConditionType::COLUMN;
        $view_column->view_column_table_id = $custom_table->id;
        $view_column->view_column_target_id = $col_del->id;
        $view_column->order = 99;
        $view_column->save();
        $this->assertGreaterThan(0, $this->viewColumnCount($col_del), 'precondition: col_del must be placed on the view');

        $importer = new TemplateImporter();
        $importer->deleteColumnsNotContainedInTemplate($this->templateJson(['col_keep']));

        $this->assertSame(['col_keep'], $this->columnNames());
        $this->assertSame(0, $this->formColumnCount($col_del), 'form columns referencing the deleted column must be removed');
        $this->assertSame(0, $this->viewColumnCount($col_del), 'view columns referencing the deleted column must be removed');

        // the form itself and other columns' references survive
        $this->assertNotNull(CustomForm::where('custom_table_id', $custom_table->id)->first());
        $this->assertGreaterThan(0, $this->formColumnCount($col_keep));
    }

    /**
     * A template containing two related tables: sync deletion only diffs the tables
     * contained in the template, and the relation itself survives.
     */
    public function testRelationTemplateOnlyDiffsOwnTables()
    {
        $json = [
            'custom_tables' => [
                $this->tableEntry(static::PARENT_TABLE_NAME, ['pcol_a', 'pcol_b']),
                $this->tableEntry(static::CHILD_TABLE_NAME, ['ccol_a', 'ccol_b']),
            ],
            'custom_relations' => [[
                'parent_custom_table_name' => static::PARENT_TABLE_NAME,
                'child_custom_table_name' => static::CHILD_TABLE_NAME,
                'relation_type' => RelationType::ONE_TO_MANY,
            ]],
        ];
        (new TemplateImporter())->import($json);

        $parent = CustomTable::getEloquent(static::PARENT_TABLE_NAME);
        $child = CustomTable::getEloquent(static::CHILD_TABLE_NAME);
        $relationQuery = CustomRelation::where('parent_custom_table_id', $parent->id)
            ->where('child_custom_table_id', $child->id);
        $this->assertTrue($relationQuery->exists(), 'precondition: relation must be created by import');

        // staging deleted ccol_b on the child table; parent untouched
        $json2 = $json;
        array_pop($json2['custom_tables'][1]['custom_columns']);

        $importer = new TemplateImporter();
        $importer->import($json2, false, false, false, ['sync_deleted_columns' => true]);

        $this->assertSame(['ccol_a'], $this->columnNamesOf(static::CHILD_TABLE_NAME));
        $this->assertSame(['pcol_a', 'pcol_b'], $this->columnNamesOf(static::PARENT_TABLE_NAME), 'other tables in template must keep all their columns');
        $this->assertSame([static::CHILD_TABLE_NAME . '.ccol_b'], $importer->syncDeletedColumns);
        $this->assertTrue($relationQuery->exists(), 'relation must survive column sync deletion');
    }

    /**
     * Deleting a select_table (reference) column removes it and its stored values,
     * without touching the referenced table or its data.
     */
    public function testDeletingSelectTableColumnKeepsReferencedData()
    {
        (new TemplateImporter())->import(['custom_tables' => [
            $this->tableEntry(static::PARENT_TABLE_NAME, ['pcol_a']),
            $this->tableEntry(static::CHILD_TABLE_NAME, ['ccol_a']),
        ]]);
        $parent = CustomTable::getEloquent(static::PARENT_TABLE_NAME);
        $child = CustomTable::getEloquent(static::CHILD_TABLE_NAME);

        // select_table column on child referencing parent
        $col_sel = new CustomColumn();
        $col_sel->custom_table_id = $child->id;
        $col_sel->column_name = 'col_sel';
        $col_sel->column_view_name = 'col_sel';
        $col_sel->column_type = ColumnType::SELECT_TABLE;
        $col_sel->options = ['select_target_table' => $parent->id];
        $col_sel->save();

        $parentValue = $parent->getValueModel();
        $parentValue->setValue(['pcol_a' => 'P']);
        $parentValue->save();

        $childValue = $child->getValueModel();
        $childValue->setValue(['ccol_a' => 'C', 'col_sel' => $parentValue->id]);
        $childValue->save();

        $importer = new TemplateImporter();
        $importer->deleteColumnsNotContainedInTemplate([
            'custom_tables' => [$this->tableEntry(static::CHILD_TABLE_NAME, ['ccol_a'])],
        ]);

        $this->assertSame(['ccol_a'], $this->columnNamesOf(static::CHILD_TABLE_NAME));
        $this->assertSame([static::CHILD_TABLE_NAME . '.col_sel'], $importer->syncDeletedColumns);

        $childValueAfter = CustomTable::getEloquent(static::CHILD_TABLE_NAME)->getValueModel()->find($childValue->id)->value;
        $this->assertSame('C', array_get($childValueAfter, 'ccol_a'));
        $this->assertArrayNotHasKey('col_sel', $childValueAfter);

        // referenced table and its data are untouched
        $this->assertSame(['pcol_a'], $this->columnNamesOf(static::PARENT_TABLE_NAME));
        $parentValueAfter = CustomTable::getEloquent(static::PARENT_TABLE_NAME)->getValueModel()->find($parentValue->id);
        $this->assertNotNull($parentValueAfter);
        $this->assertSame('P', array_get($parentValueAfter->value, 'pcol_a'));
    }

    /**
     * An indexed column has a physical virtual column on the value table;
     * sync deletion must drop it physically as well.
     */
    public function testIndexedColumnPhysicallyDropped()
    {
        (new TemplateImporter())->import($this->templateJson([
            'col_keep',
            'col_del' => ['index_enabled' => '1'],
        ]));

        $custom_table = CustomTable::getEloquent(static::TABLE_NAME);
        $col_del = CustomColumn::getEloquent('col_del', $custom_table);
        $indexColumnName = 'column_' . array_get($col_del, 'suuid');
        $dbTableName = getDBTableName($custom_table);
        $this->assertTrue(\Schema::hasColumn($dbTableName, $indexColumnName), 'precondition: virtual index column must exist');

        $importer = new TemplateImporter();
        $importer->deleteColumnsNotContainedInTemplate($this->templateJson(['col_keep']));

        $this->assertSame(['col_keep'], $this->columnNames());
        $this->assertFalse(\Schema::hasColumn($dbTableName, $indexColumnName), 'virtual index column must be dropped physically');
    }
}
