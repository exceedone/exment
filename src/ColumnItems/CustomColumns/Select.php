<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Validator\SelectRule;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Enums\FilterOption;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Field\RadioButton;
use Encore\Admin\Form\Field;

class Select extends CustomItem
{
    use ImportValueTrait;
    use SelectTrait;

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
        if (!is_array($v) && preg_match_ex('/\[.+\]/i', $v)) {
            $v = json_decode_ex($v);
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
            if (boolval($this->custom_column->getOption('check_radio_enabled', false))) {
                return Field\Checkbox::class;
            } else {
                return Field\MultipleSelect::class;
            }
        } else {
            if (boolval($this->custom_column->getOption('check_radio_enabled', false))) {
                return RadioButton::class;
            } else {
                return Field\Select::class;
            }
        }
    }

    /**
     * Get grid filter option. Use grid filter, Ex. LIKE search.
     *
     * @return string|null
     */
    protected function getGridFilterOption(): ?string
    {
        return (string)FilterOption::SELECT_EXISTS;
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

        if (boolval(array_get($this->options, 'as_modal'))) {
            $field->asModal();
        }

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


    public function isMultipleEnabled()
    {
        return $this->isMultipleEnabledTrait();
    }

    public function isFreeInput()
    {
        if (boolval($this->custom_column->getOption('check_radio_enabled', false))) {
            return false;
        }
        if (boolval(array_get($this->options, 'changefield', false))) {
            return false;
        }

        return boolval($this->custom_column->getOption('free_input', false));
    }
    protected function getFilterFieldClass()
    {
        if ($this->isMultipleEnabled()) {
            return Field\MultipleSelect::class;
        } else {
            return Field\Select::class;
        }
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

        $form->switchbool('check_radio_enabled', exmtrans("custom_column.options.check_radio_enabled"))
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans("custom_column.help.check_radio_enabled"));

        $form->switchbool('free_input', exmtrans("custom_column.options.free_input"))
            ->attribute(['data-filter' => json_encode(['parent' => 1, 'key' => 'options_check_radio_enabled', 'value' => '0'])])
            ->help(exmtrans("custom_column.help.free_input"));
    }

    /**
     * Convert filter value.
     * Ex. If value is decimal and Column Type is decimal, return floatval.
     *
     * @param mixed $value
     * @return mixed
     */
    public function convertFilterValue($value)
    {
        $isUseUnicode = \ExmentDB::isUseUnicodeMultipleColumn();

        if (is_json($value)) {
            $value = json_decode_ex($value, true);
        }
        if (is_array($value)) {
            return collect($value)->map(function ($val) use ($isUseUnicode) {
                return $this->isMultipleEnabled() && $isUseUnicode ? unicode_encode($val) : $val;
            })->toArray();
        }
        return $this->isMultipleEnabled() && $isUseUnicode ? unicode_encode($value) : $value;
    }
}
