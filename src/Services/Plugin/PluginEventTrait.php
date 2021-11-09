<?php
namespace Exceedone\Exment\Services\Plugin;

use Exceedone\Exment\Model;
use Exceedone\Exment\Model\CustomValue;

/**
 * Plugin (Event) trait
 *
 * @property boolean $isDelete
 */
trait PluginEventTrait
{
    // workflow action(if call as workflow)
    public $workflow_action;

    // notify(if call as notify)
    public $notify;

    /**
     * Init event
     *
     * @param Model\Plugin $plugin
     * @param Model\CustomTable $custom_table
     * @param Model\CustomValue $custom_value
     * @param array $options
     * @return void
     */
    protected function _initEvent($plugin, $custom_table, $custom_value, $options = [])
    {
        $this->plugin = $plugin;
        $this->custom_table = $custom_table;
        
        if ($custom_value instanceof CustomValue) {
            $this->custom_value = $custom_value;
        } elseif (!is_nullorempty($custom_value) && !is_nullorempty($custom_table)) {
            $this->custom_value = $custom_table->getValueModel($custom_value);
        }

        if (isset($options['workflow_action'])) {
            $this->workflow_action = $options['workflow_action'];
        }
        if (isset($options['notify'])) {
            $this->notify = $options['notify'];
        }
        $this->isCreate = is_nullorempty($custom_value) || $custom_value->wasRecentlyCreated;
        $this->isDelete = !is_nullorempty($custom_value) && (isset($custom_value->deleted_user_id) || isset($custom_value->deleted_at));
    }
}
