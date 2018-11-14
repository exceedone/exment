<?php

namespace Exceedone\Exment\Form\Field;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class Image extends \Encore\Admin\Form\Field\Image
{

    /**
     * If name already exists, rename it.
     * *override
     *
     * @param $file
     *
     * @return void
     */
    public function renameIfExists(UploadedFile $file)
    {
        if ($this->storage->exists("{$this->getDirectory()}/$this->name")) {
            $this->name = $this->generateUniqueName($file);
        }
    }
}
