<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\FilterOption;
use Exceedone\Exment\Grid\Filter;
use Exceedone\Exment\Form\Field as ExmentField;
use Exceedone\Exment\Model\CustomColumnMulti;

class Date extends CustomItem
{
    protected $format = 'Y-m-d';

    protected function _text($v)
    {
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

        if (!isset($this->value)) {
            return null;
        }

        return $this->getDateUseValue($this->value, $this->format);
    }

    /**
     * Get date again use format
     *
     * @return \Carbon\Carbon|null
     */
    protected function getDateUseValue($v, $format)
    {
        if (is_array($v)) {
            return (new \Carbon\Carbon(array_get($v, 'date')))->format($format) ?? null;
        }

        return (new \Carbon\Carbon($v))->format($format) ?? null;
    }

    protected function getAdminFieldClass()
    {
        if ($this->displayDate()) {
            return ExmentField\Display::class;
        }
        return Field\Date::class;
    }
    
    protected function getAdminFilterClass()
    {
        return Filter\BetweenDate::class;
    }

    protected function getCustomField($classname, $form_column_options = null, $column_name_prefix = null)
    {
        $this->autoDate();
        return parent::getCustomField($classname, $form_column_options, $column_name_prefix);
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        if ($this->displayDate()) {
            $field->default($this->getNowString());
        } else {
            $field->options(['useCurrent' => false]);
        }
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $validates[] = 'date';
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $filter->date();
    }

    /**
     * Whether this is autodate
     *
     * @return true
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
}
