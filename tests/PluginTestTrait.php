<?php

namespace Exceedone\Exment\Tests;

use Exceedone\Exment\Model\Plugin;

trait PluginTestTrait
{
    /**
     * @param string $plugin_name
     * @param string $pluginType
     * @param array<mixed> $options
     * @return array<mixed>
     */
    protected function getPluginInfo(string $plugin_name, string $pluginType, array $options = [])
    {
        /** @var Plugin $plugin */
        $plugin = Plugin::where('plugin_name', $plugin_name)->first();
        $pluginClass = $plugin->getClass($pluginType, $options);

        return [$plugin, $pluginClass];
    }
}
