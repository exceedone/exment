<?php

namespace Exceedone\Exment\Tests\Feature;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\TestDefine;

/**
 * Table and column names go into SQL. The screens check them; a template or an import saves the
 * models without the screens, so the models refuse a name with other characters themselves.
 */
class SystemNameGuardTest extends FeatureTestBase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testAColumnNameWithAQuoteIsRefused(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $column = new CustomColumn();
        $column->custom_table_id = $table->id;
        $column->column_name = "bad'name";
        $column->column_view_name = 'bad';
        $column->column_type = 'text';

        $this->expectException(\InvalidArgumentException::class);
        $column->save();
    }

    public function testAColumnNameWithATrailingNewlineIsRefused(): void
    {
        $table = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL);
        $column = new CustomColumn();
        $column->custom_table_id = $table->id;
        $column->column_name = "name\n";
        $column->column_view_name = 'bad';
        $column->column_type = 'text';

        $this->expectException(\InvalidArgumentException::class);
        $column->save();
    }

    public function testATableNameWithAQuoteIsRefused(): void
    {
        $table = new CustomTable();
        $table->table_name = 'bad"table';
        $table->table_view_name = 'bad';

        $this->expectException(\InvalidArgumentException::class);
        $table->save();
    }

    public function testAnExistingColumnStillSaves(): void
    {
        $column = CustomTable::getEloquent(TestDefine::TESTDATA_TABLE_NAME_EDIT_ALL)->custom_columns()->first();
        $this->assertNotNull($column);
        $column->column_view_name = $column->column_view_name . ' ';
        $this->assertTrue($column->save());
    }
}
