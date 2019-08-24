<?php

namespace Exceedone\Exment\Adapter;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;

trait PluginCloudTrait
{
    protected function adminDisk()
    {
        return \Storage::disk(Define::DISKNAME_PLUGIN);
    }
    
    protected function tmpDisk()
    {
        return \Storage::disk(Define::DISKNAME_PLUGIN_LOCAL);
    }
    
    public function getPluginFullPath($plugin, ...$pass_array)
    {
        $plugin_fullpath = $this->initPlugin($plugin);
        $plugin->requirePlugin($plugin_fullpath);

        return path_join($plugin_fullpath, ...$pass_array);
    }

    /**
     * init plugin
     *
     * @return void
     */
    protected function initPlugin($plugin)
    {
        $tmpDisk = $this->tmpDisk();
        /// get plugin directory
        $pathDir = $plugin->getPath();

        // if need download from cloud
        if ($this->isNeedDownload($plugin)) {
            $adminDisk = $this->adminDisk();
            
            // remove in tmp disk
            $files = $tmpDisk->allFiles($pathDir);
            foreach ($files as $file) {
                $tmpDisk->delete($file);
            }

            // get file list
            $files = $adminDisk->allFiles($pathDir);
            foreach ($files as $file) {
                $stream = $adminDisk->readStream($file);
                $tmpDisk->writeStream($file, $stream);
            }
            
            // create updated_at file
            $tmpDisk->put(path_join($pathDir, 'updated_at.txt'), $plugin->updated_at->format('YmdHis'));
        }

        return getFullpath($pathDir, Define::DISKNAME_PLUGIN_LOCAL);
    }

    /**
     * is need download from croud
     *
     * @return void
     */
    public function isNeedDownload($plugin)
    {
        /// get plugin directory
        $pathDir = $plugin->getPath();

        // if not has temp disk
        $tmpDisk = $this->tmpDisk();
        if (!$tmpDisk->exists($pathDir)) {
            return true;
        }

        // get "updated_at.txt" from tmp disk
        $updated_at_path = path_join($pathDir, 'updated_at.txt');
        if (!$tmpDisk->exists($updated_at_path)) {
            return true;
        }

        // read text
        $updated_at = $tmpDisk->get($updated_at_path);

        if ($updated_at != $plugin->updated_at->format('YmdHis')) {
            return true;
        }

        return false;
    }
}
