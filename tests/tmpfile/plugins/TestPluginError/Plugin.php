<?php
namespace App\Plugins\TestPluginError;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;

class Plugin extends PluginBatchBase
{
    /**
     * execute, called error.
     */
    public function execute()
    {
        1/0;
    }
}
