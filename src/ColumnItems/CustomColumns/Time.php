<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\ColumnDefaultType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Services\ViewFilter\ViewFilterBase;

class Time extends Date
{
    protected $format = 'H:i:s';

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return Field\Display::class;
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

    protected function setValidates(&$validates)
    {
        $validates[] = new Validator\TimeRule();
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        return [DatabaseDataType::TYPE_TIME, true, []];
    }

    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        return false;
    }


    /**
     * Get default value.
     *
     * @return mixed
     */
    protected function _getDefaultValue()
    {
        list($default_type, $default) = $this->getDefaultSetting();
        if (isMatchString($default_type, ColumnDefaultType::EXECUTING_TIME)) {
            return \Carbon\Carbon::now()->format($this->format);
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
            ->help(exmtrans("custom_column.help.default_type"))
            ->attribute(['data-filtertrigger' =>true])
            ->options(getTransArray(ColumnDefaultType::COLUMN_DEFAULT_TYPE_TIME(), 'custom_column.column_default_type_options'));

        $form->time('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->attribute(['data-filter' => json_encode(['parent' => !$asCustomForm, 'key' => $asCustomForm ? 'default_type' : 'options_default_type', 'value' => ColumnDefaultType::SELECT_TIME])])
        ;
    }

    /**
     * Set where query for grid filter. If class is "ExmWhere".
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $input
     * @return void
     */
    public function getAdminFilterWhereQuery($query, $input)
    {
        if (array_key_value_exists('start', $input)) {
            $viewFilterItem = ViewFilterBase::make(FilterOption::TIME_ON_OR_AFTER, $this);
            $viewFilterItem->setFilter($query, $input['start']);
        }
        if (array_key_value_exists('end', $input)) {
            $viewFilterItem = ViewFilterBase::make(FilterOption::TIME_ON_OR_BEFORE, $this);
            $viewFilterItem->setFilter($query, $input['end']);
        }
    }
}
