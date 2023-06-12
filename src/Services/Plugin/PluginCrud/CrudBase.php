<?php

namespace Exceedone\Exment\Services\Plugin\PluginCrud;

use Exceedone\Exment\Enums\PluginCrudAuthType;

/**
 */
abstract class CrudBase
{
    /**
     * @param $plugin
     * @param $pluginClass
     * @param $options
     * @phpstan-ignore-next-line
     */
    public function __construct($plugin, $pluginClass, $options = [])
    {
        $this->plugin = $plugin;
        $this->pluginClass = $pluginClass;
    }

    protected $plugin;
    protected $pluginClass;


    /**
     * Get full url
     *
     * @return string
     */
    public function getFullUrl(...$endpoint): string
    {
        return $this->pluginClass->getFullUrl(...$endpoint);
    }

    /**
     * Get Root full url
     * For use oauth login, logout, etc...
     *
     * @return string
     */
    public function getRootFullUrl(...$endpoint): string
    {
        return $this->pluginClass->getRootFullUrl(...$endpoint);
    }


    protected function getOAuthLogoutView()
    {
        if ($this->pluginClass->getAuthType() != PluginCrudAuthType::OAUTH) {
            return null;
        }

        if (!$this->pluginClass->enableOAuthLogoutButton()) {
            return null;
        }

        return view('exment::tools.button', [
            'href' => $this->getRootFullUrl('oauthlogout'),
            'label' => trans('admin.logout'),
            'icon' => 'fa-sign-out',
            'btn_class' => 'btn-primary',
        ]);
    }
}
