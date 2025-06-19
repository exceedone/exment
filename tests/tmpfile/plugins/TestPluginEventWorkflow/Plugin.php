<?php

namespace App\Plugins\TestPluginEventWorkflow;

use Exceedone\Exment\Services\Plugin\PluginEventBase;

class Plugin extends PluginEventBase
{
    /**
     * Plugin Event
     *
     * @return bool
     */
    public function execute()
    {
        $this->custom_value->setValue('init_text', 'workflow executed');
        $this->custom_value->save();
        return true;
    }
}
