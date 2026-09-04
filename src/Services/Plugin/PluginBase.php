<?php

namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Form;

trait PluginBase
{
    // @phpstan-ignore-next-line
    protected $plugin;

    // @phpstan-ignore-next-line
    protected $useCustomOption = false;

    // @phpstan-ignore-next-line
    protected $pluginOptions;

    // @phpstan-ignore-next-line
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
    // @phpstan-ignore-next-line
    public function getPluginOptions()
    {
        return $this->pluginOptions;
    }

    /**
     * Set the value of pluginOptions
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setPluginOptions($pluginOptions)
    {
        $this->pluginOptions = $pluginOptions;

        return $this;
    }
}
