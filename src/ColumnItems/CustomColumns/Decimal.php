<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Validator;

class Decimal extends CustomItem
{
    public function prepare()
    {
        $this->value = parseFloat($this->value);
        if (array_has($this->custom_column, 'options.decimal_digit')) {
            $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
            $this->value = floor($this->value * pow(10, $digit)) / pow(10, $digit);
        }

        return $this;
    }
    
    public function text()
    {
        if (is_null($this->value())) {
            return null;
        }

        if (boolval(array_get($this->custom_column, 'options.number_format'))
        && is_numeric($this->value())
        && !boolval(array_get($this->options, 'disable_number_format'))) {
            return number_format($this->value());
        }
        return $this->value();
    }

    protected function getAdminFieldClass()
    {
        return Field\Text::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        if (!is_null(array_get($options, 'number_min'))) {
            $field->attribute(['min' => array_get($options, 'number_min')]);
        }
        if (!is_null(array_get($options, 'number_max'))) {
            $field->attribute(['max' => array_get($options, 'number_max')]);
        }

        if (!is_null(array_get($options, 'decimal_digit'))) {
            $field->attribute(['decimal_digit' => array_get($options, 'decimal_digit')]);
        }
    }
    
    protected function setValidates(&$validates)
    {
        $validates[] = new Validator\IntegerCommaRule;
    }
}
