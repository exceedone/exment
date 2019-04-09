<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

class Currency extends Decimal
{
    public function text()
    {
        if (is_null($this->value())) {
            return null;
        }

        if (boolval(array_get($this->custom_column, 'options.number_format'))
        && is_numeric($this->value())
        && !boolval(array_get($this->options, 'disable_number_format'))) {
            if (array_has($this->custom_column, 'options.decimal_digit')) {
                $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
                $value = number_format($this->value(), $digit);
                $value = preg_replace("/\.?0+$/",'', $value);
            } else {
                $value = number_format($this->value());
            }
        } else {
            $value = $this->value();
        }

        if (boolval(array_get($this->options, 'disable_currency_symbol'))) {
            return $value;
        }
        // get symbol
        $symbol = array_get($this->custom_column, 'options.currency_symbol');
        return getCurrencySymbolLabel($symbol, $value);
    }

    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        // get symbol
        $symbol = array_get($options, 'currency_symbol');
        $field->prepend($symbol);
        $field->attribute(['style' => 'max-width: 200px']);
    }

    /**
     * get sort column name as SQL
     */
    public function getSortColumn()
    {
        $column_name = $this->index();
        if (array_has($this->custom_column, 'options.decimal_digit')) {
            $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
            return "CAST($column_name AS DECIMAL(50, $digit))";
        } else {
            return "CAST($column_name AS SIGNED)";
        }
    }
}
