<?php
namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Enums\DatabaseDataType;

class Decimal extends CustomItem
{
    public function prepare()
    {
        if (!is_null($this->value())) {
            $this->value = parseFloat($this->value);
            if (array_has($this->custom_column, 'options.decimal_digit')) {
                $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
                $this->value = floor($this->value * pow(10, $digit)) / pow(10, $digit);
            }
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
            if (array_has($this->custom_column, 'options.decimal_digit')) {
                $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
                $number = number_format($this->value(), $digit);
                return preg_replace("/\.?0+$/", '', $number);
            } else {
                return number_format($this->value());
            }
        }
        return $this->value();
    }
    public function saving()
    {
        $rmv = rmcomma($this->value);
        if (!isset($rmv)) {
            return null;
        }
        return $rmv;
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
        $field->attribute('style', 'max-width: 200px');
    }
    
    protected function setValidates(&$validates)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'number_min')) {
            $validates[] = new Validator\NumberMinRule(array_get($options, 'number_min'));
        }
        if (array_get($options, 'number_max')) {
            $validates[] = new Validator\NumberMaxRule(array_get($options, 'number_max'));
        }
        $validates[] = new Validator\DecimalCommaRule;
    }
    /**
     * get cast name for sort
     */
    public function getCastName()
    {
        $grammar = \DB::getQueryGrammar();
        if (array_has($this->custom_column, 'options.decimal_digit')) {
            return $grammar->getCastString(DatabaseDataType::TYPE_DECIMAL, true, [
                'length' => 50,
                'decimal_digit' => intval(array_get($this->custom_column, 'options.decimal_digit', 2))
            ]);
        } else {
            return $grammar->getCastString(DatabaseDataType::TYPE_DECIMAL, true);
        }
    }

    /**
     * whether column is Numeric
     *
     */
    public function isNumeric()
    {
        return true;
    }
}
