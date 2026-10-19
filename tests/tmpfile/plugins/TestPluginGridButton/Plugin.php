<?php

namespace App\Plugins\TestPluginGridButton;

use Exceedone\Exment\Services\Plugin\PluginButtonBase;

class Plugin extends PluginButtonBase
{
    /**
     * A button on the list screen has no record of its own: it answers which records the list
     * sent, so a test can see that the selection reached the plugin.
     *
     * @return array<string, mixed>
     */
    public function execute()
    {
        return [
            'result' => true,
            'swaltext' => 'selected: ' . $this->selected_custom_values->pluck('id')->sort()->implode(','),
        ];
    }
}
