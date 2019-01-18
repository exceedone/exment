<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\AwsS3v3;

use Exceedone\Exment\Model\File;

class ExmentAdapterS3 extends AwsS3Adapter
{
    /**
     * Get URL using File class
     */
    public function getUrl($path)
    {
        return File::getUrl($path);
    }
}
