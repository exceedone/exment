<?php

namespace App\Plugins\TestPluginEventNotify;

use Exceedone\Exment\Services\Plugin\PluginEventBase;

class Plugin extends PluginEventBase
{
    /**
     * Plugin Event
     */
    public function execute()
    {
        $this->custom_value->setValue('init_text', 'notify executed');
        $this->custom_value->save();
        return true;
    }
}
