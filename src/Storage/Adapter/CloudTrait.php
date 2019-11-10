<?php

namespace Exceedone\Exment\Storage\Adapter;

use Exceedone\Exment\Model\File;
use Exceedone\Exment\Model\Define;
use League\Flysystem\Adapter\Local;
use League\Flysystem\Filesystem;

trait CloudTrait
{
    /**
     * Set tmp disk. tmp disk is only local
     *
     * @param array $config
     * @return void
     */
    public function setTmpDisk($config)
    {
        $this->tmpDisk = new Filesystem(new Local(array_get($config, 'root')));
        
        return $this;
    }
}
