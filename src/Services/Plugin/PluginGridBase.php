<?php

namespace Exceedone\Exment\Services\Plugin;
use Encore\Admin\Form;

/**
 * Plugin view base class
 */
abstract class PluginGridBase extends PluginPublicBase
{
    use PluginBase, PluginPageTrait;

    protected $custom_table;
    protected $custom_view;

    /**
     * Whether using box.
     *
     * @var bool
     */
    protected $useBox = true;

    /**
     * Whether using box buttons.
     *
     * @var array
     */
    protected $useBoxButtons = [
        'newButton',
        'menuButton',
        'viewButton',
    ];


    public function __construct($plugin, $custom_table, $custom_view)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }


    /**
     * Whether using box.
     *
     * @return bool
     */
    public function useBox() : bool{
        return $this->useBox ?? true;
    }

    /**
     * Whether using box buttons.
     *
     * @return array
     */
    public function useBoxButtons() : array{
        return $this->useBoxButtons ?? [];
    }


    /**
     * Set view option form for setting
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setViewOptionForm($form)
    {
    }


    abstract public function grid();
    

}
