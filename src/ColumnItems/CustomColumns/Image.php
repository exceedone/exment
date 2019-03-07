<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;

class Image extends File
{
    protected function getAdminFieldClass()
    {
        return Field\Image::class;
    }
}
