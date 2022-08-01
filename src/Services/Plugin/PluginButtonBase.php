<?php

namespace Exceedone\Exment\Services\Plugin;

use Illuminate\Contracts\Support\Renderable;

/**
 * Plugin (Button) base class
 */
class PluginButtonBase
{
    use PluginBase;
    use PluginButtonTrait;
    use PluginPageTrait;

    public $custom_table;
    public $custom_value;

    /**
     * Selected custom values if button is grid
     *
     * @var \Illuminate\Support\Collection
     */
    public $selected_custom_values;

    public $isCreate;

    public function __construct($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->_initButton($plugin, $custom_table, $custom_value, $options);

        $this->selected_custom_values = array_get($options, 'selected_custom_values', collect());
    }

    public function execute()
    {
    }

    /**
     * Render button freeformat.
     *
     * @return string|Renderable|null
     */
    public function render()
    {
    }
}
