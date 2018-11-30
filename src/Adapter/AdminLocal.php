<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\Adapter\Local;

use Exceedone\Exment\Model\File;

class AdminLocal extends Local
{
    /**
     * Get URL using File class
     */
    public function getUrl($path)
    {
        return File::getUrl($path);
    }
}
