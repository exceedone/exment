<?php

namespace Exceedone\Exment\Tests\Feature\Line;

use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Menu;
use Exceedone\Exment\Services\Line\LineInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;
use Exceedone\Exment\Tests\Feature\FeatureTestBase;
use Exceedone\Exment\Tests\TestTrait;

/**
 * Guards the "release = run the migrations" promise for the LINE side: whatever
 * a fresh install (InstallSeeder) and an upgrade (the dated migrations) run,
 * both go through LineInstaller::ensureAll(), and re-running it must converge
 * rather than duplicate.
 *
 * The test DB is already seeded by "exment:inittest", so these assert the
 * SHIPPED shape plus re-run safety -- the from-nothing creation path is not
 * exercised here (dropping the custom tables would need DDL, which MySQL
 * auto-commits and would break DatabaseTransactions).
 */
class LineInstallerTest extends FeatureTestBase
{
    use TestTrait;
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initAllTest();
    }

    public function testEnsureAllCreatesTablesAndMenu()
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

    /**
     * line_send_log.flex_template is a SELECT_TABLE pointing at line_flex_template,
     * so ensureAll() has to create the flex table FIRST. Run them the other way
     * round and the foreign table id silently ends up null -- this pins the order.
     */
    public function testSendLogFlexTemplateColumnPointsAtFlexTemplateTable()
    {
        LineInstaller::ensureAll();

        $flex = CustomTable::getEloquent('line_flex_template');
        $log = CustomTable::getEloquent('line_send_log');
        $column = CustomColumn::getEloquent('flex_template', $log);

        $this->assertEquals($flex->id, array_get($column->options, 'select_target_table'));
    }

    public function testEnsureAllIsIdempotent()
    {
        LineInstaller::ensureAll();
        LineInstaller::ensureAll();

        $this->assertEquals(1, CustomTable::where('table_name', 'line_flex_template')->count());
        $this->assertEquals(1, CustomTable::where('table_name', 'line_send_log')->count());
        $this->assertEquals(1, Menu::where('menu_name', 'line_link')->count());
    }
}
