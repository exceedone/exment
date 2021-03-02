<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Validator\SelectRule;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Form\Field\RadioButton;
use Exceedone\Exment\Grid\Filter as ExmFilter;
use Encore\Admin\Form\Field;

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
        if ($this->isMultipleEnabled()) {
            if (boolval($this->custom_column->getOption('checkbox_enabled', false))) {
                return Field\Checkbox::class;
            } else {
                return Field\MultipleSelect::class;
            }
        } else {
            if (boolval($this->custom_column->getOption('radiobutton_enabled', false))) {
                return RadioButton::class;
            } else {
                return Field\Select::class;
            }
        }
    }
    
    protected function getAdminFilterClass()
    {
        if ($this->isMultipleEnabled()) {
            return ExmFilter\Where::class;
        }
        return ExmFilter\EqualOrIn::class;
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        $type = $this->isMultipleEnabled() ? DatabaseDataType::TYPE_STRING_MULTIPLE : DatabaseDataType::TYPE_STRING;
        return [$type, false, []];
    }

    protected function setAdminOptions(&$field)
    {
        $options = $this->custom_column->createSelectOptions();

        if ($this->isFreeInput()) {
            $field->freeInput(true);

            if (isset($this->value)) {
                $array = $this->value;
                if (!is_array($array)) {
                    $array = [$array];
                }
                foreach ($array as $value) {
                    if (!in_array($value, $options)) {
                        $options[$value] = $value;
                    }
                }
            }
        }

        $field->options($options);

        if ($field instanceof RadioButton && !$this->required()) {
            $field->addEmpty(true);
        }
    }
    
    protected function setValidates(&$validates)
    {
        if (!$this->isFreeInput()) {
            $select_options = $this->custom_column->createSelectOptions();
            $validates[] = new SelectRule(array_keys($select_options));
        }
    }

    protected function getRemoveValidates()
    {
        return [\Encore\Admin\Validator\HasOptionRule::class];
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $options = $this->custom_column->createSelectOptions();
        $filter->multipleSelect($options);
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

    public function isFreeInput()
    {
        if (boolval($this->custom_column->getOption('radiobutton_enabled', false)) ||
            boolval($this->custom_column->getOption('checkbox_enabled', false))) {
            return false;
        }
        return boolval($this->custom_column->getOption('free_input', false));
    }
    protected function getFilterFieldClass()
    {
        return Field\Select::class;
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
        $form->textarea('select_item', exmtrans("custom_column.options.select_item"))
            ->required()
            ->help(exmtrans("custom_column.help.select_item"));
            
        $form->switchbool('multiple_enabled', exmtrans("custom_column.options.multiple_enabled"))
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans("custom_column.help.multiple_enabled"));

        $form->switchbool('radiobutton_enabled', exmtrans("custom_column.options.radiobutton_enabled"))
            ->attribute(['data-filtertrigger' =>true, 'data-filter' => json_encode(['parent' => 1, 'key' => 'options_multiple_enabled', 'value' => '0'])])
            ->help(exmtrans("custom_column.help.radiobutton_enabled"));

        $form->switchbool('checkbox_enabled', exmtrans("custom_column.options.checkbox_enabled"))
            ->attribute(['data-filtertrigger' =>true, 'data-filter' => json_encode(['parent' => 1, 'key' => 'options_multiple_enabled', 'value' => '1'])])
            ->help(exmtrans("custom_column.help.checkbox_enabled"));

        $form->switchbool('free_input', exmtrans("custom_column.options.free_input"))
            ->attribute(['data-filter' => json_encode([
                ['parent' => 1, 'key' => 'options_radiobutton_enabled', 'value' => '0'],
                ['parent' => 1, 'key' => 'options_checkbox_enabled', 'value' => '0']])])
            ->help(exmtrans("custom_column.help.free_input"));
    }

}
