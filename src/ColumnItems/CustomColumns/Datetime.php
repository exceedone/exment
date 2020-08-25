<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Grid\Filter;

class Datetime extends Date
{
    protected $format = 'Y-m-d H:i:s';

    protected function getDisplayFormat()
    {
        if (FilterKind::useDate(array_get($this->options, 'filterKind'))) {
            return config('admin.date_format');
        } else {
            return config('admin.datetime_format');
        }
    }

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return ExmentField\Display::class;
        }
        if (FilterKind::useDate(array_get($this->options, 'filterKind'))) {
            return Field\Date::class;
        }
        return Field\Datetime::class;
    }

    protected function getAdminFilterClass()
    {
        return Filter\BetweenDatetime::class;
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        return [DatabaseDataType::TYPE_DATETIME, true, []];
    }
}
