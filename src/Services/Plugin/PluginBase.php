<?php

namespace Exceedone\Exment\Services\Plugin;

trait PluginBase
{
    protected $plugin;

    protected $useCustomOption = false;

    public function useCustomOption()
    {
        return $this->useCustomOption;
    }

    /**
     * Set Custom Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param [type] $form
     * @return void
     */
    public function setCustomOptionForm(&$form)
    {
    }
}
