<?php

namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Form;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;
use Exceedone\Exment\DataItems\Grid\PluginGrid;

/**
 * Plugin view base class
 */
abstract class PluginViewBase extends PluginPublicBase
{
    use PluginBase;
    use PluginPageTrait;

    /**
     * @var CustomTable
     */
    protected $custom_table;

    /**
     * @var CustomView
     */
    protected $custom_view;
    /**
     * Whether using box.
     *
     * @var bool|null
     */
    protected $useBox = true;

    /**
     * Whether using box buttons.
     *
     * @var array|null
     */
    protected $useBoxButtons = [
        'newButton',
        'menuButton',
        'viewButton',
    ];


    public function __construct(Plugin $plugin, ?CustomTable $custom_table, ?CustomView $custom_view)
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
    public function useBox(): bool
    {
        return $this->useBox ?? true;
    }

    /**
     * Whether using box buttons.
     *
     * @return array
     */
    public function useBoxButtons(): array
    {
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


    /**
     * Set Default column fileds form
     *
     * @param Form $form
     * @return void
     */
    public function setColumnFields(Form &$form)
    {
        PluginGrid::setColumnFields($form, $this->custom_table, [
            'include_workflow' => false,
            'include_parent' => true,
            'include_child' => true,
        ]);
    }


    /**
     * Set Default filter fileds form
     *
     * @param Form $form
     * @return void
     */
    public function setFilterFields(Form &$form)
    {
        PluginGrid::setFilterFields($form, $this->custom_table);
    }


    /**
     * Set Sort fileds form
     *
     * @param Form $form
     * @return void
     */
    public function setSortFields(Form &$form)
    {
        PluginGrid::setSortFields($form, $this->custom_table);
    }

    abstract public function grid();
}
