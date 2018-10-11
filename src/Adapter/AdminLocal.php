<?php

namespace Exceedone\Exment\Adapter;

use League\Flysystem\Adapter\Local;

use League\Flysystem\Config;
use Exceedone\Exment\Model\File;

class AdminLocal extends Local
{
    /**
     * @inheritdoc
     */
    public function write($path, $contents, Config $config)
    {
        $path = File::saveFileInfo($path);
        return parent::write($path, $contents, $config);
    }

    /**
     * @inheritdoc
     */
    public function writeStream($path, $resource, Config $config)
    {
        $path = File::saveFileInfo($path);
        return parent::writeStream($path, $resource, $config);
    }

    /**
     * @inheritdoc
     */
    public function readStream($path)
    {
        return parent::readStream($path);
    }

    /**
     * @inheritdoc
     */
    public function updateStream($path, $resource, Config $config)
    {
        return parent::updateStream($path, $resource, $config);
    }

    /**
     * @inheritdoc
     */
    public function update($path, $contents, Config $config)
    {
        return parent::update($path, $contents, $config);
    }

    /**
     * @inheritdoc
     */
    public function read($path)
    {
        return parent::read($path);
    }

    /**
     * @inheritdoc
     */
    public function rename($path, $newpath)
    {
        return parent::rename($path, $newpath);
    }

    /**
     * @inheritdoc
     */
    public function copy($path, $newpath)
    {
        return parent::copy($path, $newpath);
    }

    /**
     * @inheritdoc
     */
    public function delete($path)
    {
        return parent::delete($path);
    }

    /**
     * @inheritdoc
     */
    public function has($path)
    {
        $location = $this->applyPathPrefix($path);

        return file_exists($location);
    }

    /**
     * Get URL using File class
     */
    public function getUrl($path)
    {
        $path = File::getUrl($path); 

        // TODO:hard coding. It's OK?
        return admin_url("files/".$path);
        // if(!starts_with($path, "/storage")){
        //     $path = "/storage/" . $path;
        // }

        // return $path;
    }

    /**
     * Prefix a path.
     *
     * @param string $path
     *
     * @return string prefixed path
     */
    public function applyPathPrefix($path)
    {
        return $this->getPathPrefix() . ltrim($path, '\\/');
    }

}
