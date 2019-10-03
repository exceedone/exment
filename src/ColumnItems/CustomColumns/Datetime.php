<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Field as ExmentField;

class Datetime extends Date
{
    protected $format = 'Y-m-d H:i:s';

    protected function getDisplayFormat()
    {
        return config('admin.datetime_format');
    }

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return ExmentField\Display::class;
        }
        return Field\Datetime::class;
    }
}
