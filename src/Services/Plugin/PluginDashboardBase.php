<?php

namespace Exceedone\Exment\Services\Plugin;

/**
 * Plugin (dashboard) base class
 */
class PluginDashboardBase extends PluginPublicBase
{
    use PluginBase, PluginPageTrait;

    protected $dashboard;
    protected $dashboard_box;

    public function __construct($plugin, $dashboard_box)
    {
        $this->plugin = $plugin;
        $this->dashboard_box = $dashboard_box;
    }

    /**
     * Get Dashboard Box Header html
     *
     * @return string
     */
    public function header()
    {
        return null;
    }
    
    /**
     * Get Dashboard Box body html
     *
     * @return string
     */
    public function body()
    {
        return null;
    }

    /**
     * Get Dashboard Box footer html
     *
     * @return string
     */
    public function footer()
    {
        return null;
    }
    
    /**
     * Get route uri for dashboard
     *
     * @return string
     */
    public function getDashboardUri($endpoint = null)
    {
        return url_join(
            'dashboardbox',
            'plugin',
            $this->plugin->getOptionUri(),
            (isset($this->dashboard_box) ? $this->dashboard_box->suuid : '{suuid}'),
            $endpoint
        );
    }
}
