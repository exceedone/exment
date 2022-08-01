<?php

namespace App\Plugins\TestPluginStaticFunction;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;

class Plugin extends PluginBatchBase
{
    /**
     * execute
     */
    public function execute()
    {
        // call test static funciton
        StaticFunction::testPluginStaticFunction();
        Dir1\StaticFunction::testPluginStaticFunctionDir1();
    }
}
