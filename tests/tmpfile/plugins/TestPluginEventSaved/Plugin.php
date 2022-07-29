<?php

namespace App\Plugins\TestPluginEventSaved;

use Exceedone\Exment\Services\Plugin\PluginEventBase;
use Exceedone\Exment\Model\CustomTable;

class Plugin extends PluginEventBase
{
    /**
     * Plugin Event
     */
    public function execute()
    {
        $id = $this->custom_value->id;
        $custom_value = CustomTable::getEloquent('custom_value_view_all')->getValueModel($id);
        if (isset($custom_value)) {
            $val = $custom_value->getValue('integer');
            $custom_value->setValue('integer', $val + 100);
            $custom_value->save();
        }
        return true;
    }
}
