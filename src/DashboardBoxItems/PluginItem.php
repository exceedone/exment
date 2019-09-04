<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\Enums\DashboardBoxSystemPage;
use Exceedone\Exment\Model\Plugin;

class PluginItem implements ItemInterface
{
    protected $dashboard_box;
    protected $plugin;
    protected $pluginItem;
    
    public function __construct($dashboard_box)
    {
        $this->dashboard_box = $dashboard_box;

        // get plugin 
        $this->plugin = Plugin::getEloquent($dashboard_box->getOption('target_plugin_id'));
        // get class
        $this->pluginItem = $this->plugin->getClass();
    }

    /**
     * get header
     */
    public function header()
    {
        //TODO:
        return null;
    }
    
    /**
     * get footer
     */
    public function footer()
    {
        //TODO:
        return null;
    }
    
    /**
     * get html body
     */
    public function body()
    {
        return $this->pluginItem->dashboard();
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        // show plugin list
        $plugins = Plugin::getPluginPages();
        $options = $plugins->mapWithKeys(function($plugin){
            return [$plugin->_plugin()->id => $plugin->_plugin()->plugin_name];
        });
        $form->select('target_plugin_id', exmtrans("dashboard.dashboard_box_options.target_plugin_id"))
            ->required()
            ->options($options)
            ;
    }

    /**
     * saving event
     */
    public static function saving(&$form)
    {
    }

    public static function getItem(...$args)
    {
        list($dashboard_box) = $args + [null];
        return new self($dashboard_box);
    }
}
