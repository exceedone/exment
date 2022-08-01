<?php

namespace App\Plugins\TestPluginTrait;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;

class Plugin extends PluginBatchBase
{
    use TestTrait;
    use Dir1\TestTrait;

    /**
     * execute
     */
    public function execute()
    {
        // call test test funciton
        $this->testTrait();
        $this->dir1TestTrait();
    }
}
