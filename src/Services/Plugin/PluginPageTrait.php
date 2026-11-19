<?php

/**
 * Execute Batch
 */

namespace Exceedone\Exment\Services\Plugin;

trait PluginPageTrait
{
    /**
     * get load view if view exists and path
     *
     * @return array|null|void
     */
    // @phpstan-ignore-next-line
    public function _getLoadView()
    {
        $base_path = $this->plugin->getFullPath(path_join('resources', 'views'));
        if (!\File::exists($base_path)) {
            return null;
        }

        return [$base_path, 'exment_' . snake_case($this->plugin->plugin_name)];
    }

    /**
     * Path and namespace of the plugin's own translation files, or null when
     * the plugin ships none. Mirrors _getLoadView so a plugin keeps its blades
     * and its strings under the same namespace.
     *
     * @return array|null
     */
    // @phpstan-ignore-next-line
    public function _getLoadTranslation()
    {
        $base_path = $this->plugin->getFullPath(path_join('resources', 'lang'));
        if (!\File::exists($base_path)) {
            return null;
        }

        return [$base_path, 'exment_' . snake_case($this->plugin->plugin_name)];
    }

    /**
     * return view. and append plugin's prefix automatic.
     *
     * @param string $bladeName
     * @param array $data
     * @return mixed
     */
    // @phpstan-ignore-next-line
    protected function pluginView($bladeName, $data = [])
    {
        $blade = 'exment_' . snake_case($this->plugin->plugin_name) . '::' . $bladeName;
        return view($blade, $data);
    }
}
