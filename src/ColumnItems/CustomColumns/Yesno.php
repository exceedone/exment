<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Validator;

class Yesno extends CustomItem
{
    use ImportValueTrait;

    /**
     * laravel-admin set required. if false, always not-set required
     */
    protected $required = false;

    protected function _text($v)
    {
        return getYesNo($v);
    }

    public function saving()
    {
        // get custom_value's value.
        $custom_value_value = array_get($this->custom_value, 'value');
        if (is_nullorempty($custom_value_value)) {
            return;
        }

        // if not has key in $custom_value_value, and this is edited, return;
        // *Why this function needs, if already saved as true and edit call, and not contains this field,
        // if not has this function, override 0.
        if (!array_has($custom_value_value, $this->custom_column->column_name) && !is_nullorempty($this->custom_value->id)) {
            return;
        }

        if (is_null($this->value)) {
            return 0;
        }
        if (strtolower($this->value) === 'yes') {
            return 1;
        }
        if (strtolower($this->value) === 'no') {
            return 0;
        }
        return boolval($this->value) ? 1 : 0;
    }

    protected function getAdminFieldClass()
    {
        if (boolval(array_get($this->custom_column, 'options.checkbox_enabled'))) {
            return Field\Checkboxone::class;
        } else {
            return Field\SwitchBoolField::class;
        }
    }

    protected function setAdminOptions(&$field)
    {
        if (boolval(array_get($this->custom_column, 'options.checkbox_enabled'))) {
            $field->option([
                1 => ''
            ]);
        }

        if (array_boolval($this->custom_column->options, 'required_yes')) {
            $field->requiredRule();
        }
    }

    protected function setAdminFilterOptions(&$filter)
    {
        $filter->radio(Define::YESNO_RADIO);
    }

    protected function setValidates(&$validates)
    {
        $validates[] = new Validator\YesNoRule();
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
    public function getImportValueOption()
    {
        return [
            0    => getYesNo(0),
            1    => getYesNo(1),
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
     * Set Custom Column Option default value Form. Using laravel-admin form option
     * https://laravel-admin.org/docs/#/en/model-form-fields
     *
     * @param Form $form
     * @return void
     */
    public function setCustomColumnDefaultValueForm(&$form, bool $asCustomForm = false)
    {
        if ($asCustomForm) {
            $form->radio('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
            ->options([
                '0' => 'NO',
                '1' => 'YES',
            ])->addEmpty(true);
            return;
        }
        $form->switchbool('default', exmtrans("custom_column.options.default"))
            ->help(exmtrans("custom_column.help.default"))
        ;
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
        $form->switchbool('checkbox_enabled', exmtrans("custom_column.options.checkbox_enabled"))
            ->help(exmtrans("custom_column.help.checkbox_enabled"));
        $form->switchbool('required_yes', exmtrans("custom_column.options.required_yes"))
            ->help(exmtrans("custom_column.help.required_yes"));
    }

    public function getFalseValue()
    {
        return 0;
    }
    public function getTrueValue()
    {
        return 1;
    }
}
