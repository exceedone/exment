<?php

namespace Exceedone\Exment\DashboardBoxItems;

use Exceedone\Exment\Enums\PluginType;
use Exceedone\Exment\Enums\Permission;
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
        if (!isset($this->plugin)) {
            return;
        }

        $this->pluginItem = $this->plugin->getClass(PluginType::DASHBOARD, ['dashboard_box' => $dashboard_box, 'throw_ex' => false]);
    }

    /**
     * get header
     */
    public function header()
    {
        if (($result = $this->hasPermission()) !== true) {
            return null;
        }

        return $this->pluginItem->header();
    }

    /**
     * get html body
     */
    public function body()
    {
        if (($result = $this->hasPermission()) !== true) {
            return $result;
        }

        return $this->pluginItem->body();
    }

    /**
     * get footer
     */
    public function footer()
    {
        if (($result = $this->hasPermission()) !== true) {
            return null;
        }

        return $this->pluginItem->footer();
    }

    /**
     * get dashboard attributes for display html
     *
     * @return array
     */
    public function attributes()
    {
        return [
            'plugin_id' => isset($this->plugin) ? $this->plugin->id : null,
            'plugin_uuid' => isset($this->plugin) ? $this->plugin->uuid : null,
            'plugin_name' => isset($this->plugin) ? $this->plugin->plugin_name : null,
            'plugin_view_name' => isset($this->plugin) ? $this->plugin->plugin_view_name : null,
        ];
    }

    /**
     * set laravel admin embeds option
     */
    public static function setAdminOptions(&$form, $dashboard)
    {
        // show plugin list
        $plugins = Plugin::getByPluginTypes(PluginType::DASHBOARD);
        $options = $plugins->mapWithKeys(function ($plugin) {
            return [$plugin->id => $plugin->plugin_view_name];
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

    /**
     * Has show permission this dashboard item
     *
     * @return array|\Illuminate\Contracts\Translation\Translator|string|true|null
     */
    protected function hasPermission()
    {
        // if table not found, break
        if (!isset($this->plugin)) {
            return exmtrans('dashboard.message.not_exists_plugin');
        }

        // if not access permission
        if (!\Exment::user()->hasPermissionPlugin($this->plugin, Permission::PLUGIN_ACCESS)) {
            return trans('admin.deny');
        }

        if (!isset($this->pluginItem)) {
            return $this->plugin->getCannotReadMessage();
        }

        return true;
    }
}
