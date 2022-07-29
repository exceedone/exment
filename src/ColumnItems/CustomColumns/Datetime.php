<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\FilterKind;
use Exceedone\Exment\Enums\ColumnDefaultType;
use Exceedone\Exment\Grid\Filter as ExmFilter;

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
            return Field\Display::class;
        }
        if (FilterKind::useDate(array_get($this->options, 'filterKind'))) {
            return Field\Date::class;
        }
        return Field\Datetime::class;
    }

    protected function getAdminFilterClass()
    {
        return ExmFilter\BetweenDatetime::class;
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        return [DatabaseDataType::TYPE_DATETIME, true, []];
    }

    /**
     * whether column is datetime
     *
     */
    public function isDateTime()
    {
        return true;
    }


    /**
     * Get default value.
     *
     * @return mixed
     */
    protected function _getDefaultValue()
    {
        list($default_type, $default) = $this->getDefaultSetting();
        if (isMatchString($default_type, ColumnDefaultType::EXECUTING_DATETIME)) {
            return \Carbon\Carbon::now()->format($this->format);
        }
        if (isMatchString($default_type, ColumnDefaultType::EXECUTING_TODAY)) {
            return \Carbon\Carbon::today()->format($this->format);
        }

        return null;
    }


    /**
     * Set Custom Column Option default value Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
        $form->select('default_type', exmtrans("custom_column.options.default_type"))
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans("custom_column.help.default_type"))
            ->options(getTransArray(ColumnDefaultType::COLUMN_DEFAULT_TYPE_DATETIME(), 'custom_column.column_default_type_options'));

        $form->datetime('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->attribute(['data-filter' => json_encode(['parent' => !$asCustomForm, 'key' => $asCustomForm ? 'default_type' : 'options_default_type', 'value' => ColumnDefaultType::SELECT_DATETIME])])
        ;
    }
}
