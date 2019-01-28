<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Validator;

class Integer extends CustomItem
{
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
        return Field\Number::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        if (!boolval(array_get($options, 'updown_button'))) {
            $field->disableUpdown();
            $field->defaultEmpty();
        }

        if (!is_null(array_get($options, 'number_min'))) {
            $field->attribute(['min' => array_get($options, 'number_min')]);
        }
        if (!is_null(array_get($options, 'number_max'))) {
            $field->attribute(['max' => array_get($options, 'number_max')]);
        }
    }

    protected function setValidates(&$validates)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'number_min')) {
            $validates[] = 'min:'.array_get($options, 'number_min');
        }
        if (array_get($options, 'number_max')) {
            $validates[] = 'max:'.array_get($options, 'number_max');
        }

        $validates[] = new Validator\IntegerCommaRule;
    }
}
