<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\Enums\PluginType;
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
        if(isset($this->plugin)){
            $this->pluginItem = $this->plugin->getClass(PluginType::DASHBOARD, ['dashboard_box' => $dashboard_box]);
        }
    }

    /**
     * get header
     */
    public function header()
    {
        if(!isset($this->pluginItem)){
            return null;
        }
        return $this->pluginItem->header();
    }
        
    /**
     * get html body
     */
    public function body()
    {
        if(!isset($this->pluginItem)){
            return null;
        }
        return $this->pluginItem->body();
    }

    /**
     * get footer
     */
    public function footer()
    {
        if(!isset($this->pluginItem)){
            return null;
        }
        return $this->pluginItem->footer();
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        // show plugin list
        $plugins = Plugin::getByPluginTypes(PluginType::DASHBOARD);
        $options = $plugins->mapWithKeys(function($plugin){
            return [$plugin->id => $plugin->plugin_name];
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
