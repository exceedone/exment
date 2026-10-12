<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Services\Line\LineInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestTrait;

class LineInstallerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testEnsureAllCreatesTablesAndMenu(): void
    {
        LineInstaller::ensureAll();

        $flex = CustomTable::getEloquent('line_flex_template');
        $this->assertNotNull($flex);
        $this->assertTrue(boolval($flex->system_flg));
        foreach (['flex_key', 'template_name', 'title', 'body_items'] as $col) {
            $this->assertNotNull(CustomColumn::getEloquent($col, $flex), "missing column {$col}");
        }

        $log = CustomTable::getEloquent('line_send_log');
        $this->assertNotNull($log);
        $this->assertTrue(boolval($log->system_flg));
        foreach (['line_user_id', 'message_type', 'flex_template', 'subject', 'body', 'user', 'send_datetime', 'status', 'error_message'] as $col) {
            $this->assertNotNull(CustomColumn::getEloquent($col, $log), "missing column {$col}");
        }

        $this->assertTrue(Menu::where('menu_name', 'line_link')->exists());
    }

    public function testSendLogFlexTemplateColumnPointsAtFlexTemplateTable(): void
    {
        LineInstaller::ensureAll();

        $flex = CustomTable::getEloquent('line_flex_template');
        $log = CustomTable::getEloquent('line_send_log');
        $column = CustomColumn::getEloquent('flex_template', $log);

        $this->assertEquals($flex->id, array_get($column->options, 'select_target_table'));
    }

    public function testEnsureAllIsIdempotent(): void
    {
        LineInstaller::ensureAll();
        LineInstaller::ensureAll();

        $this->assertEquals(1, CustomTable::where('table_name', 'line_flex_template')->count());
        $this->assertEquals(1, CustomTable::where('table_name', 'line_send_log')->count());
        $this->assertEquals(1, Menu::where('menu_name', 'line_link')->count());
    }
    public function testEnsureAllRefusesForeignTableWithSameName(): void
    {
        LineInstaller::ensureAll();
        $real = CustomTable::getEloquent('line_flex_template');
        $real->table_name = 'zz_line_flex_template_backup';
        $real->save();
        \Exceedone\Exment\Model\System::clearCache();

        $foreign = CustomTable::create([
            'table_name'      => 'line_flex_template',
            'table_view_name' => 'Customer table',
            'options'         => [],
        ]);
        \Exceedone\Exment\Model\System::clearCache();

        $thrown = null;
        try {
            LineInstaller::ensureAll();
        } catch (\RuntimeException $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'ensureAll() must refuse a same-name table that is not the feature\'s.');
        $this->assertFalse(boolval(CustomTable::where('id', $foreign->id)->firstOrFail()->system_flg));
        $this->assertEquals(0, CustomColumn::where('custom_table_id', $foreign->id)->count());
    }

    /**
     * migrate:reset (exment:inittest) rolls migrations back newest-first: upstream
     * 2026_07_01_000000_create_workflow_task_reads_table drops workflow_task_reads BEFORE
     * LINE 2026_06_29_000001_create_line_flex_template_table deletes its custom table, and
     * CustomTable::deletingChildren() clears workflow_task_reads for the deleted table.
     * Pin: deleting a custom table must not require workflow_task_reads to exist.
     * RENAME TABLE is DDL and commits the test transaction: everything is restored in finally.
     * A run killed before finally leaves the backup table behind (exment:inittest recreates
     * workflow_task_reads but not this backup), so the leftover is cleaned up first.
     */
    public function testCustomTableCanBeDeletedWhenWorkflowTaskReadsTableIsAbsent(): void
    {
        $reads = (new \Exceedone\Exment\Model\WorkflowTaskRead())->getTable();
        if (\Schema::hasTable("{$reads}_zz_bak")) {
            if (\Schema::hasTable($reads)) {
                \Schema::drop("{$reads}_zz_bak");
            } else {
                \DB::statement("RENAME TABLE `{$reads}_zz_bak` TO `{$reads}`");
            }
        }
        $probeName = 'zz_line_rollback_probe';
        CustomTable::where('table_name', $probeName)->delete();
        $probe = CustomTable::create([
            'table_name'      => $probeName,
            'table_view_name' => 'rollback probe',
            'options'         => [],
        ]);
        \Exceedone\Exment\Model\System::clearCache();

        \DB::statement("RENAME TABLE `{$reads}` TO `{$reads}_zz_bak`");
        try {
            $probe->delete();
            \Exceedone\Exment\Model\System::clearCache();

            $this->assertNull(CustomTable::find($probe->id), 'custom table row must be deleted even without workflow_task_reads');
        } finally {
            \DB::statement("RENAME TABLE `{$reads}_zz_bak` TO `{$reads}`");
            CustomTable::where('table_name', $probeName)->delete();
            \Exceedone\Exment\Model\System::clearCache();
        }
    }
}
