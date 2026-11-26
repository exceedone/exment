<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * The plugins of a table are kept for the request (ISS02906), and a plugin saved in the same
 * request is seen at once: the list is not left old.
 */
class PluginsByTableCacheTest extends UnitTestBase
{
    use DatabaseTransactions;

    public function testAPluginSavedInTheRequestIsSeen(): void
    {
        $table = CustomTable::getEloquent('custom_value_edit');
        $this->assertNotNull($table);
        $names = function () use ($table): array {
            $names = [];
            foreach (Plugin::getPluginsByTable($table, false) as $plugin) {
                $names[] = $plugin->plugin_name;
            }
            return $names;
        };

        $before = $names();
        $this->assertNotContains('PbtCacheTestPlugin', $before);
        // asked again: the same
        $this->assertSame($before, $names());

        $plugin = new Plugin();
        $plugin->uuid = (string)\Illuminate\Support\Str::uuid();
        $plugin->plugin_name = 'PbtCacheTestPlugin';
        $plugin->plugin_view_name = 'PbtCacheTest';
        $plugin->plugin_types = PluginType::TRIGGER;
        $plugin->active_flg = true;
        $plugin->version = '1.0.0';
        $plugin->options = ['target_tables' => [$table->table_name]];
        $plugin->save();

        $this->assertContains('PbtCacheTestPlugin', $names(), 'a plugin saved in the request is seen');

        $plugin->delete();
        $this->assertNotContains('PbtCacheTestPlugin', $names(), 'a plugin deleted in the request is gone');
    }
}
