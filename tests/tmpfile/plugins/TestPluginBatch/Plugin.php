<?php

namespace App\Plugins\TestPluginBatch;

use Exceedone\Exment\Services\Plugin\PluginBatchBase;
use Exceedone\Exment\Model\CustomTable;

class Plugin extends PluginBatchBase
{
    /**
     * execute
     */
    public function execute()
    {
        $tables = CustomTable::all();

        foreach ($tables as $table) {
            $modelname = getModelName($table);
            if ($modelname === null) {
                continue;
            }

            $modelname::onlyTrashed()
                ->forceDelete();
        }
    }
}
