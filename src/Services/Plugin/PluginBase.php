<?php

namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Form;

trait PluginBase
{
    protected $plugin;

    protected $useCustomOption = false;

    protected $pluginOptions;

    public function useCustomOption()
    {
        return $this->useCustomOption;
    }

    /**
     * Set Custom Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomOptionForm(&$form)
    {
    }

    /**
     * Get the value of pluginOptions
     */
    public function getPluginOptions()
    {
        return $this->pluginOptions;
    }

    /**
     * Set the value of pluginOptions
     *
     * @return  self
     */
    public function setPluginOptions($pluginOptions)
    {
        $this->pluginOptions = $pluginOptions;

        return $this;
    }
}
