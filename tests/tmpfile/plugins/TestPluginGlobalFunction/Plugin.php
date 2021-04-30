<?php
namespace App\Plugins\TestPluginGlobalFunction;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;

class Plugin extends PluginBatchBase
{
    /**
     * execute
     */
    public function execute()
    {
        // call test global funciton
        testPluginGlobalFunction();
        Dir1\testPluginGlobalFunctionDir1();
    }
}
