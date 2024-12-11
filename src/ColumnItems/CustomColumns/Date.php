<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\ColumnDefaultType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Grid\Filter as ExmFilter;
use Exceedone\Exment\Model\CustomColumnMulti;

class Date extends CustomItem
{
    protected $format = 'Y-m-d';

    protected function _text($v)
    {
        if ($this->displayDate() && boolval(array_get($this->options, 'public_form')) && !isset($v)) {
            return exmtrans('custom_value.auto_number_create');
        }

        // if not empty format, using carbon
        $format = array_get($this->custom_column, 'options.format');
        if (is_nullorempty($format)) {
            $format = array_get($this->options, 'format');
        }
        if (is_nullorempty($format)) {
            $format = $this->getDisplayFormat();
        }

        if (!isset($v)) {
            return null;
        }

        if (!is_nullorempty($format) && !boolval(array_get($this->options, 'summary'))) {
            return $this->getDateUseValue($v, $format);
        }

        // else, return
        return $this->_value($v);
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        return [DatabaseDataType::TYPE_DATE, true, []];
    }

    protected function getDisplayFormat()
    {
        return config('admin.date_format');
    }

    public function saving()
    {
        if ($this->autoDate()) {
            $this->value = $this->getNowString();
            return $this->value;
        }

        if (isset($this->id) && boolval(array_get($this->custom_column, 'options.datetime_now_creating'))) {
            return $this->getOriginalValue();
        }

        if (!isset($this->value)) {
            return null;
        }

        return $this->getDateUseValue($this->value, $this->format);
    }

    /**
     * Get date again use format
     *
     * @param $v
     * @param $format
     * @return string|null
     */
    protected function getDateUseValue($v, $format)
    {
        if (is_array($v)) {
            /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
            return (new \Carbon\Carbon(array_get($v, 'date')))->format($format) ?? null;
        }

        /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
        return (new \Carbon\Carbon($v))->format($format) ?? null;
    }

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return Field\Display::class;
        }
        return Field\Date::class;
    }


    protected function getCustomField($classname, $column_name_prefix = null)
    {
        $this->autoDate();
        return parent::getCustomField($classname, $column_name_prefix);
    }

    protected function setAdminOptions(&$field)
    {
        if ($this->displayDate()) {
            $field->default(exmtrans('custom_value.auto_number_create'));
            $field->setInternal(true);
        } else {
            $field->options(['useCurrent' => false]);
        }
    }

    protected function setValidates(&$validates)
    {
        $validates[] = 'date';
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $filter->date();
    }

    protected function getAdminFilterClass()
    {
        return ExmFilter\BetweenDate::class;
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
        $this->getAdminFilterWhereQueryDate($query, $input);
    }

    /**
     * Whether this is autodate
     *
     * @return bool
     */
    protected function autoDate()
    {
        $autoDate = false;

        // if datetime_now_saving is true
        if (boolval(array_get($this->custom_column, 'options.datetime_now_saving'))) {
            $autoDate = true;
        }
        // if not has id(creating) and datetime_now_creating is true
        elseif (!isset($this->id) && boolval(array_get($this->custom_column, 'options.datetime_now_creating'))) {
            $autoDate = true;
        }

        if ($autoDate) {
            $this->required = false;
            return true;
        }

        return false;
    }

    /**
     * Whether only display
     *
     * @return bool
     */
    protected function displayDate()
    {
        // if datetime_now_saving is true
        return boolval(array_get($this->custom_column, 'options.datetime_now_saving')) || boolval(array_get($this->custom_column, 'options.datetime_now_creating'));
    }

    /**
     * get now string for saving
     *
     * @return string now string
     */
    protected function getNowString()
    {
        return \Carbon\Carbon::now()->format($this->format);
    }

    /**
     * whether column is date
     *
     */
    public function isDate()
    {
        return true;
    }

    /**
     * Compare two values.
     */
    public function compareTwoValues(CustomColumnMulti $compare_column, $this_value, $target_value)
    {
        try {
            $this_date = new \Carbon\Carbon($this_value);
            $target_date = new \Carbon\Carbon($target_value);

            switch ($compare_column->compare_type) {
                case FilterOption::COMPARE_GT:
                    if ($this_date->gt($target_date)) {
                        return true;
                    }

                    return $compare_column->getCompareErrorMessage('validation.not_gt_date', $compare_column->compare_column1, $compare_column->compare_column2);

                case FilterOption::COMPARE_GTE:
                    if ($this_date->gte($target_date)) {
                        return true;
                    }

                    return $compare_column->getCompareErrorMessage('validation.not_gte_date', $compare_column->compare_column1, $compare_column->compare_column2);

                case FilterOption::COMPARE_LT:
                    if ($this_date->lt($target_date)) {
                        return true;
                    }

                    return $compare_column->getCompareErrorMessage('validation.not_lt_date', $compare_column->compare_column1, $compare_column->compare_column2);

                case FilterOption::COMPARE_LTE:
                    if ($this_date->lte($target_date)) {
                        return true;
                    }

                    return $compare_column->getCompareErrorMessage('validation.not_lte_date', $compare_column->compare_column1, $compare_column->compare_column2);
            }
        }
        // if throw, return true. (Maybe validates as format other logic.)
        catch (\Exception $ex) {
            return true;
        }
    }


    /**
     * Get default value.
     *
     * @return mixed
     */
    protected function _getDefaultValue()
    {
        list($default_type, $default) = $this->getDefaultSetting();
        if (isMatchString($default_type, ColumnDefaultType::EXECUTING_DATE)) {
            return \Carbon\Carbon::now()->format($this->format);
        }

        return null;
    }


    /**
     * Set Custom Column Option Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnOptionForm(&$form)
    {
        // date, time, datetime
        $form->switchbool('datetime_now_saving', exmtrans("custom_column.options.datetime_now_saving"))
            ->help(exmtrans("custom_column.help.datetime_now_saving"))
            ->default("0");

        $form->switchbool('datetime_now_creating', exmtrans("custom_column.options.datetime_now_creating"))
            ->help(exmtrans("custom_column.help.datetime_now_creating"))
            ->default("0");
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
            ->options(getTransArray(ColumnDefaultType::COLUMN_DEFAULT_TYPE_DATE(), 'custom_column.column_default_type_options'));

        $form->date('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->attribute(['data-filter' => json_encode(['parent' => !$asCustomForm, 'key' => $asCustomForm ? 'default_type' : 'options_default_type', 'value' => ColumnDefaultType::SELECT_DATE])])
        ;
    }
}
