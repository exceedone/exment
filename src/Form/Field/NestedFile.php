<?php

namespace Encore\Admin\Form\Field;

namespace Exceedone\Exment\Form\Field;
use Encore\Admin\Form\Field;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class NestedFile extends \Encore\Admin\Form\Field\File
{
    protected $view = 'admin::form.file';
    /**
     * Destroy original files.
     *
     * @return void.
     */
    public function destroy()
    {
        // get file path
        if ($this->storage->exists($this->original)) {
            $this->storage->delete($this->original);
        }
    }
}
