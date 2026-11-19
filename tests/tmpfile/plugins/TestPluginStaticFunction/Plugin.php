<?php

namespace App\Plugins\TestPluginStaticFunction;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;

class Plugin extends PluginBatchBase
{
    /**
     * Call the static functions this plugin defines in its own classes.
     * Answering false fails the batch (exit code 1), which the test checks.
     *
     * @return false|null
     */
    public function execute()
    {
        // call test static funciton
        if (!StaticFunction::testPluginStaticFunction() || !Dir1\StaticFunction::testPluginStaticFunctionDir1()) {
            return false;
        }
        return null;
    }
}
