<?php

namespace Exceedone\Exment\Form\Field;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class Image extends \Encore\Admin\Form\Field\Image
{
    /**
     *  Validation rules.
     *
     * @var array
     */
    protected $rules = [];

    /**
     * If name already exists, rename it.
     * *override
     *
     * @param UploadedFile $file
     *
     * @return void
     */
    public function renameIfExists(UploadedFile $file)
    {
        if ($this->storage->exists("{$this->getDirectory()}/$this->name")) {
            $this->name = $this->generateUniqueName($file);
        }
    }
    
    /**
     * Render file upload field.
     *
     * @return \Illuminate\Contracts\View\Factory|\Illuminate\View\View
     */
    public function render()
    {
        $this->filetype('image');
        return parent::render();
    }
}
