<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Validator;

class Time extends Date
{
    protected $format = 'H:i:s';

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return ExmentField\Display::class;
        }
        return Field\Time::class;
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $filter->time();
    }
    
    protected function getDisplayFormat()
    {
        return config('admin.time_format');
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $validates[] = new Validator\TimeRule();
    }

    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        return false;
    }
}
