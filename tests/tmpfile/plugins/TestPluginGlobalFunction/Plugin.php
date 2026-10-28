<?php

namespace App\Plugins\TestPluginGlobalFunction;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;

class Plugin extends PluginBatchBase
{
    /**
     * Call the functions this plugin defines in its own files. Answering
     * false fails the batch (exit code 1), which the test checks.
     *
     * @return false|null
     */
    public function execute()
    {
        require_once dirname(__FILE__).'/function.php';
        require_once dirname(__FILE__).'/Dir1/function.php';

        // call test global funciton
        if (!testPluginGlobalFunction() || !Dir1\testPluginGlobalFunctionDir1()) {
            return false;
        }
        return null;
    }
}
