<?php
namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\DatabaseDataType;

class Decimal extends CustomItem
{
    use NumberTrait;
    
    public function prepare()
    {
        if (!is_null($this->value)) {
            $this->value = parseFloat($this->value);
            if (array_has($this->custom_column, 'options.decimal_digit')) {
                $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
                $this->value = floor($this->value * pow(10, $digit)) / pow(10, $digit);
            }
        }
        return $this;
    }

    /**
     * get html(for display)
     */
    protected function _html($v)
    {
        // default escapes text
        $text = boolval(array_get($this->options, 'grid_column')) ? get_omitted_string($this->_text($v)) : $this->_text($v);
        // display number as percent
        if (array_has($this->custom_column, 'options.percent_format')) {
            if (boolval(array_get($this->custom_column, 'options.percent_format')) && isset($text)) {
                $text = strval(parseFloat($text) * 100) . '%';
            }
        }
        return esc_html($text);
    }
    
    protected function _text($v)
    {
        if (is_null($v)) {
            return null;
        }
        if (boolval(array_get($this->custom_column, 'options.number_format'))
        && is_numeric($v)
        && !boolval(array_get($this->options, 'disable_number_format'))) {
            if (array_has($this->custom_column, 'options.decimal_digit')) {
                $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
                $number = number_format($v, $digit);
                return preg_replace("/\.?0+$/", '', $number);
            } else {
                return number_format($v);
            }
        }
        return $this->_value($v);
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
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'number_min')) {
            $validates[] = new Validator\NumberMinRule(array_get($options, 'number_min'));
        } else {
            $validates[] = new Validator\NumberMinRule(-1 * Define::MAX_SIZE_NUMBER);
        }

        if (array_get($options, 'number_max')) {
            $validates[] = new Validator\NumberMaxRule(array_get($options, 'number_max'));
        } else {
            $validates[] = new Validator\NumberMaxRule(Define::MAX_SIZE_NUMBER);
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
}
