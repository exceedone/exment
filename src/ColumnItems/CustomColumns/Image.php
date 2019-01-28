<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;

class Image extends File 
{
    protected function getAdminFieldClass(){
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleImage::class;
        } else {
            return Field\Image::class;
        }
    }
}
