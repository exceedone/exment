<?php

namespace Exceedone\Exment\Services\Plugin;

use Encore\Admin\Grid\Filter;
use Exceedone\Exment\Model\Plugin;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomView;

/**
 * Plugin view filter base class
 */
abstract class PluginViewFilterBase
{
    use PluginBase;

    /**
     * @var CustomTable
     */
    protected $custom_table;

    /**
     * @var CustomView
     */
    protected $custom_view;

    public function __construct(Plugin $plugin, ?CustomTable $custom_table, ?CustomView $custom_view)
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->custom_view = $custom_view;
    }


    /**
     * Execute grid filter
     *
     * @param Filter $filter
     * @return void
     */
    abstract public function grid_filter(Filter $filter);
}
