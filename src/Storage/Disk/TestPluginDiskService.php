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
        $this->plugin = $plugin;
        $path = isset($plugin) ? $plugin->getPath() : null;

        $this->diskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_SYNC), $path, $path);
        $this->tmpDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_TEST), $path, $path);
        $this->localSyncDiskItem = new DiskServiceItem(Storage::disk(Define::DISKNAME_PLUGIN_TEST), $path, $path);
    }
}
