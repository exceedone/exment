<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Validator\SelectRule;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Grid\Filter\Where as ExmWhere;
use Encore\Admin\Form\Field;
use Encore\Admin\Grid\Filter;

class Select extends CustomItem
{
    use ImportValueTrait, SelectTrait;
    
    protected function _value($v)
    {
        return $this->getResultForSelect($v, false);
    }

    protected function _text($v)
    {
        return $this->getResultForSelect($v, true);
    }

    protected function getResultForSelect($v, $label)
    {
        $select_options = $this->custom_column->createSelectOptions();
        // if $value is array
        $multiple = true;
        if (!is_array($v) && preg_match('/\[.+\]/i', $v)) {
            $v = json_decode($v);
        }
        if (!is_array($v)) {
            $val = [$v];
            $multiple = false;
        } else {
            $val = $v;
        }
        // switch column_type and get return value
        $returns = $this->getReturnsValue($select_options, $val, $label);
        
        if ($multiple) {
            return $label ? implode(exmtrans('common.separate_word'), $returns) : $returns;
        } else {
            return $returns[0];
        }
    }

    protected function getReturnsValue($select_options, $val, $label)
    {
        return $val;
    }
    
    protected function getAdminFieldClass()
    {
        if (boolval(array_get($this->custom_column, 'options.multiple_enabled'))) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
    }
    
    protected function getAdminFilterClass()
    {
        if (boolval($this->custom_column->getOption('multiple_enabled'))) {
            return ExmWhere::class;
        }
        return Filter\Equal::class;
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        $type = $this->isMultipleEnabled() ? DatabaseDataType::TYPE_STRING_MULTIPLE : DatabaseDataType::TYPE_STRING;
        return [$type, false, []];
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        $field->options($this->custom_column->createSelectOptions());
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $select_options = $this->custom_column->createSelectOptions();
        $validates[] = new SelectRule(array_keys($select_options));
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $options = $this->custom_column->createSelectOptions();
        $filter->select($options);
    }
    
    /**
     * replace value for import
     *
     * @return array
     */
    protected function getImportValueOption()
    {
        return $this->custom_column->createSelectOptions();
    }
    
    public function getAdminFilterWhereQuery($query, $input)
    {
        $this->getSelectFilterQuery($query, $input);
    }
    
    /**
     * sortable for grid
     */
    public function sortable()
    {
        if ($this->isMultipleEnabled()) {
            return false;
        }
        return parent::sortable();
    }

    public function isMultipleEnabled()
    {
        return $this->isMultipleEnabledTrait();
    }
    protected function getFilterFieldClass()
    {
        return Field\Select::class;
    }
}
