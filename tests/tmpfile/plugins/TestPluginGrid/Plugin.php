<?php

namespace App\Plugins\TestPluginGrid;

use Exceedone\Exment\Services\Plugin\PluginGridBase;

class Plugin extends PluginGridBase
{
    /**
     *
     */
    public function grid()
    {
        return view('exment_test_plugin_grid::sample');
    }
}