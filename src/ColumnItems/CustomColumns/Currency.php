<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\Enums\CurrencySymbol;

class Currency extends Decimal
{
    protected function _text($v)
    {
        list($symbol, $value) = $this->getSymbolAndValue($v);
        if (!isset($symbol)) {
            return $value;
        }

        return getCurrencySymbolLabel($symbol, false, $value);
    }

    protected function _html($v)
    {
        list($symbol, $value) = $this->getSymbolAndValue($v);
        if (!isset($symbol)) {
            return $value;
        }

        return getCurrencySymbolLabel($symbol, true, $value);
    }

    protected function getSymbolAndValue($v)
    {
        if (is_null($v)) {
            return [null, null];
        }

        if (boolval(array_get($this->custom_column, 'options.number_format'))
        && is_numeric($v)
        && !boolval(array_get($this->options, 'disable_number_format'))) {
            if (array_has($this->custom_column, 'options.decimal_digit')) {
                $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
                $value = number_format($v, $digit);
            //$value = preg_replace("/\.?0+$/",'', $value);
            } else {
                $value = number_format($v);
            }
        } else {
            $value = $v;
        }

        if (boolval(array_get($this->options, 'disable_currency_symbol'))) {
            return [null, $value];
        }
        // get symbol
        $symbol = array_get($this->custom_column, 'options.currency_symbol');
        return [$symbol, $value];
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        parent::setAdminOptions($field, $form_column_options);
        
        $options = $this->custom_column->options;
        
        // get symbol
        $symbol = CurrencySymbol::getEnum(array_get($options, 'currency_symbol'));
        if (isset($symbol)) {
            $field->prepend(array_get($symbol->getOption(), 'html'));
        }
        $field->attribute(['style' => 'max-width: 200px']);
    }
}
