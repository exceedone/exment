<?php

namespace Exceedone\Exment\Storage\Disk;

use Exceedone\Exment\Model\Define;
use Illuminate\Support\Facades\Storage;

/**
 * Plugin disk service for test
 */
class TestPluginDiskService extends PluginDiskService
{
    public function initDiskService($plugin)
    {
        parent::initDiskService($plugin);
        $path = isset($plugin) ? $plugin->getPath() : null;

        $this->localSyncDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_TEST), $path, $path);
    }
}
