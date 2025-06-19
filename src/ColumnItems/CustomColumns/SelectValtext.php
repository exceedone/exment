<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Validator;
use Encore\Admin\Form;

class SelectValtext extends Select
{
    use ImportValueTrait;

    protected function getReturnsValue($select_options, $val, $label)
    {
        // switch column_type and get return value
        $returns = [];
        // loop keyvalue
        foreach ($val as $v) {
            // set whether $label
            if (is_null($v)) {
                $returns[] = null;
            } else {
                $returns[] = $label ? array_get($select_options, $v) : $v;
            }
        }
        return $returns;
    }

    protected function setValidates(&$validates)
    {
        $select_options = $this->custom_column->createSelectOptions();
        $validates[] = new Validator\SelectValTextRule($select_options);
    }

    public function saving()
    {
        $v = $this->_getPureValue($this->value, true);
        if (!is_nullorempty($v)) {
            if ($this->isMultipleEnabled()) {
                return is_list($v) ? $v : [$v];
            }
            return is_list($v) ? $v[0] : $v;
        }
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

    /**
     * Get pure value. If you want to change the search value, change it with this function.
     * Call from freeword search.
     *
     * @param string $label
     * @return ?array array:matched, null:not matched
     */
    public function getPureValue($label)
    {
        return $this->_getPureValue($label, false);
    }

    /**
     * Get pure value. If you want to change the search value, change it with this function.
     *
     * @param string $label
     * @param bool $isCallFromSaving if true, called from saving function.
     * @return ?array array:matched, null:not matched
     */
    protected function _getPureValue($label, bool $isCallFromSaving)
    {
        $result = [];
        foreach ($this->custom_column->createSelectOptions() as $key => $q) {
            // If called from saving, check as exact match.
            if($isCallFromSaving && $q == $label){
                $result[] = $key;
            }
            // If called from freeword search, check as partial match.
            elseif (!$isCallFromSaving && isMatchStringPartial($q, $label)) {
                $result[] = $key;
            }
        }
        if (count($result) === 0) {
            return null;
        }
        return $result;
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
        // define select-item
        $form->textarea('select_item_valtext', exmtrans("custom_column.options.select_item"))
            ->required()
            ->help(exmtrans("custom_column.help.select_item_valtext"))
            ->rules([new Validator\SelectValTextSettingRule()]);

        // enable multiple
        $form->switchbool('multiple_enabled', exmtrans("custom_column.options.multiple_enabled"))
            ->attribute(['data-filtertrigger' =>true])
            ->help(exmtrans("custom_column.help.multiple_enabled"));

        $form->switchbool('check_radio_enabled', exmtrans("custom_column.options.check_radio_enabled"))
            ->help(exmtrans("custom_column.help.check_radio_enabled"));
    }
    
    /**
     * Set Search orWhere for free text search
     *
     * @param Builder $mark
     * @param string $mark
     * @param string $value
     * @param string|null $q
     * @return $this
     */
    public function setSearchOrWhere(&$query, $mark, $value, $q)
    {
        if (!$this->isMultipleEnabled()) {
            return parent::setSearchOrWhere($query, '=', $q, $q);
        }
        return $this->_setSearchOrWhere($query, $mark, $value, $q);
    }
}
