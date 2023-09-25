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
    public function _getLoadView()
    {
        $base_path = $this->plugin->getFullPath(path_join('resources', 'views'));
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
    protected function pluginView($bladeName, $data = [])
    {
        $blade = 'exment_' . snake_case($this->plugin->plugin_name) . '::' . $bladeName;
        return view($blade, $data);
    }
}
