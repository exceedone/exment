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
    public function testEnsureAllRefusesForeignTableWithSameName()
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
        $this->assertFalse(boolval(CustomTable::find($foreign->id)->system_flg));
        $this->assertEquals(0, CustomColumn::where('custom_table_id', $foreign->id)->count());
    }
}
