<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;
use Exceedone\Exment\Tests\DatabaseTransactions;

/**
 * ISS00418: installing a plugin again (an update, by hand or from a market) keeps its row, but
 * PluginInstaller::prepareData() set active_flg from the plugin type each time, so a plugin the
 * administrator had turned off was turned on again by the update. A new plugin still starts on,
 * and a new batch plugin off.
 */
class PluginInstallerKeepsActiveFlagTest extends UnitTestBase
{
    use DatabaseTransactions;

    protected const UUID = '7e4b1c20-0418-4a6d-8f3e-2b1c0d9e8f70';

    public function testANewPluginStartsOn(): void
    {
        $this->assertTrue(boolval($this->prepareData('page')->active_flg));
    }

    public function testANewBatchPluginStartsOff(): void
    {
        $this->assertFalse(boolval($this->prepareData('batch')->active_flg));
    }

    public function testAnUpdateKeepsAPluginTurnedOff(): void
    {
        $this->prepareData('page', '1.0.0', false)->save();

        $updated = $this->prepareData('page', '1.1.0');
        $this->assertTrue($updated->exists);
        $this->assertSame('1.1.0', $updated->version);
        $this->assertFalse(boolval($updated->active_flg), 'a plugin turned off stays off after an update');
    }

    public function testAnUpdateKeepsABatchPluginTurnedOn(): void
    {
        $this->prepareData('batch', '1.0.0', true)->save();

        $this->assertTrue(boolval($this->prepareData('batch', '1.1.0')->active_flg), 'a batch turned on stays on after an update');
    }

    private function prepareData(string $type, string $version = '1.0.0', ?bool $active = null): Plugin
    {
        $method = new \ReflectionMethod(PluginInstaller::class, 'prepareData');
        $method->setAccessible(true);
        $plugin = $method->invoke(null, [
            'plugin_name' => 'TestPluginIss418',
            'uuid' => static::UUID,
            'plugin_view_name' => 'Plugin ISS00418',
            'description' => 'active_flg on update',
            'author' => 'Exment',
            'version' => $version,
            'plugin_type' => $type,
        ]);
        $this->assertInstanceOf(Plugin::class, $plugin);
        if (isset($active)) {
            $plugin->active_flg = $active;
        }

        return $plugin;
    }
}
