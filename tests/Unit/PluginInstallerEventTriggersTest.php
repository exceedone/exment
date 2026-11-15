<?php

namespace Exceedone\Exment\Tests\Unit;

use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Services\Plugin\PluginInstaller;

/**
 * #1712: config.json may give "event_triggers" as a comma separated string ("saved,deleted").
 * PluginInstaller::prepareData() split it into an array and then overwrote the array with the
 * raw string again, so the plugin was stored with options.event_triggers = "saved,deleted" and
 * was never called (the event is looked up with in_array()).
 *
 * prepareData() only builds the Plugin model, nothing is saved here.
 */
class PluginInstallerEventTriggersTest extends UnitTestBase
{
    public function testCommaSeparatedEventTriggersAreStoredAsArray(): void
    {
        $plugin = $this->prepareData(['event_triggers' => 'saved,deleted']);

        $this->assertSame(['saved', 'deleted'], array_get($plugin->options, 'event_triggers'));
    }

    public function testCommaSeparatedEventTriggersAreTrimmed(): void
    {
        $plugin = $this->prepareData(['event_triggers' => 'saved, deleted']);

        $this->assertSame(['saved', 'deleted'], array_get($plugin->options, 'event_triggers'));
    }

    public function testSingleEventTriggerIsStoredAsArray(): void
    {
        $plugin = $this->prepareData(['event_triggers' => 'saved']);

        $this->assertSame(['saved'], array_get($plugin->options, 'event_triggers'));
    }

    public function testArrayEventTriggersAreKept(): void
    {
        $plugin = $this->prepareData(['event_triggers' => ['saved', 'deleted']]);

        $this->assertSame(['saved', 'deleted'], array_get($plugin->options, 'event_triggers'));
    }

    /**
     * The other comma separated options were already stored as arrays, and the plain options as given.
     */
    public function testOtherOptionsAreUnchanged(): void
    {
        $plugin = $this->prepareData([
            'event_triggers' => 'saved,deleted',
            'target_tables' => 'custom_value_edit_all,custom_value_edit',
            'label' => 'issue1712 label',
        ]);

        $this->assertSame(['custom_value_edit_all', 'custom_value_edit'], array_get($plugin->options, 'target_tables'));
        $this->assertSame('issue1712 label', array_get($plugin->options, 'label'));
    }

    /**
     * @param array<string, mixed> $json values of config.json added to the required ones
     */
    private function prepareData(array $json): Plugin
    {
        $method = new \ReflectionMethod(PluginInstaller::class, 'prepareData');
        $method->setAccessible(true);
        $plugin = $method->invoke(null, array_merge([
            'plugin_name' => 'TestPluginIssue1712',
            'uuid' => '5d2f7a40-1712-4c1e-9a55-0a1b2c3d4e5f',
            'plugin_view_name' => 'Plugin issue 1712',
            'description' => 'event_triggers as a comma separated string',
            'author' => 'Exment',
            'version' => '1.0.0',
            'plugin_type' => 'event',
        ], $json));
        $this->assertInstanceOf(Plugin::class, $plugin);

        return $plugin;
    }
}
