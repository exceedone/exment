<?php

namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomValue;
use Exceedone\Exment\Model\Plugin as PluginModel;

/**
 * Plugin (Format) base class
 */
class PluginFormatBase
{
    use PluginBase;

    /**
     * Tagret custom table
     *
     * @var CustomTable
     */
    public $custom_table;

    /**
     * original custom value
     *
     * @var CustomValue|null
     */
    public $custom_value;

    /**
     * Whether this plugin is called.
     *
     * @var mixed
     */
    public $called_type;

    /**
     * Construct.
     *
     * @param PluginModel $plugin
     * @param CustomTable|null $custom_table
     * @param $custom_value
     * @param array $options
     *      'called_type' => Whether this plugin is called.
     */
    public function __construct(PluginModel $plugin, ?CustomTable $custom_table, $custom_value, array $options = [])
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        $this->called_type = array_get($options, 'called_type');

        if ($custom_value instanceof CustomValue) {
            $this->custom_value = $custom_value;
        } elseif (!is_nullorempty($custom_value) && !is_nullorempty($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value);
        }
    }

    public function format()
    {
    }
}
