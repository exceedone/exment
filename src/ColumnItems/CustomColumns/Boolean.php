<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Validator;

class Boolean extends CustomItem
{
    use ImportValueTrait;

    /**
     * laravel-admin set required. if false, always not-set required
     */
    protected $required = false;

    protected function _text($v)
    {
        if ($this->getTrueValue() == $v) {
            return array_get($this->custom_column, 'options.true_label');
        } elseif ($this->getFalseValue() == $v) {
            return array_get($this->custom_column, 'options.false_label');
        }
        return null;
    }

    public function saving()
    {
        // get custom_value's value.
        $custom_value_value = array_get($this->custom_value, 'value');
        if (is_nullorempty($custom_value_value)) {
            return;
        }

        // if not has key in $custom_value_value, and this is edited, return;
        // *Why this function needs, if already saved as 1 and edit call, and not contains this field,
        // if not has this function, override false value.
        if (!array_has($custom_value_value, $this->custom_column->column_name) && !is_nullorempty($this->custom_value->id)) {
            return;
        }

        // only call custom column has
        if (is_null($this->value)) {
            return array_get($this->custom_column, 'options.false_value');
        }
    }

    protected function getAdminFieldClass()
    {
        return Field\SwitchField::class;
    }


    protected function setValidates(&$validates)
    {
        $option = $this->getImportValueOption();
        $validates[] = new Validator\BooleanRule($option);
    }

    protected function setAdminOptions(&$field)
    {
        $options = $this->custom_column->options;

        // set options
        $states = [
            'on'  => ['value' => $this->getTrueValue(), 'text' => array_get($options, 'true_label')],
            'off' => ['value' => $this->getFalseValue(), 'text' => array_get($options, 'false_label')],
        ];
        $field->states($states);
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $column = $this->custom_column;
        $filter->radio([
            ''   => 'All',
            $this->getFalseValue()    => array_get($column, 'options.false_label'),
            $this->getTrueValue()    => array_get($column, 'options.true_label'),
        ]);
    }

    protected function getRemoveValidates()
    {
        return [\Encore\Admin\Validator\HasOptionRule::class];
    }

    /**
     * replace value for import
     *
     * @return array
     */
    protected function getImportValueOption()
    {
        $column = $this->custom_column;
        return [
            $this->getFalseValue()    => array_get($column, 'options.false_label'),
            $this->getTrueValue()    => array_get($column, 'options.true_label')
        ];
    }

    /**
     * Get pure value. If you want to change the search value, change it with this function.
     *
     * @param string $label
     * @return ?string string:matched, null:not matched
     */
    public function getPureValue($label)
    {
        $option = $this->getImportValueOption();

        foreach ($option as $value => $l) {
            if (strtolower($label) == strtolower($l)) {
                return $value;
            }
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
        // yes/no ----------------------------
        $form->text('true_value', exmtrans("custom_column.options.true_value"))
            ->help(exmtrans("custom_column.help.true_value"))
            ->required();

        $form->text('true_label', exmtrans("custom_column.options.true_label"))
            ->help(exmtrans("custom_column.help.true_label"))
            ->required()
            ->default(exmtrans("custom_column.options.true_label_default"));

        $form->text('false_value', exmtrans("custom_column.options.false_value"))
            ->help(exmtrans("custom_column.help.false_value"))
            ->required();

        $form->text('false_label', exmtrans("custom_column.options.false_label"))
            ->help(exmtrans("custom_column.help.false_label"))
            ->required()
            ->default(exmtrans("custom_column.options.false_label_default"));
    }

    public function getFalseValue()
    {
        return array_get($this->custom_column, 'options.false_value');
    }
    public function getTrueValue()
    {
        return array_get($this->custom_column, 'options.true_value');
    }
}
