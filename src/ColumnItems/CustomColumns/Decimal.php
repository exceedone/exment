<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\DatabaseDataType;
use Exceedone\Exment\Grid\Filter as ExmFilter;

class Decimal extends CustomItem
{
    use NumberTrait;

    public function prepare()
    {
        if (!is_null($this->value)) {
            if (is_list($this->value)) {
                $this->value = collect($this->value)->map(function ($v) {
                    return $this->_format($v);
                });
            } else {
                $this->value = $this->_format($this->value);
            }
        }
        return $this;
    }

    protected function _format($v)
    {
        $v = parseFloat($v);
        if (array_has($this->custom_column, 'options.decimal_digit')) {
            $digit = intval(array_get($this->custom_column, 'options.decimal_digit'));
            $v = floorDigit($v, $digit);
        }
        return $v;
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
        return strval($rmv);
    }
    protected function getAdminFieldClass()
    {
        return Field\Text::class;
    }

    protected function setAdminOptions(&$field)
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

        $field->attribute(['style' => 'max-width: 200px']);

        if (array_key_value_exists('decimal_digit', $options)) {
            $digit = intval(array_get($options, 'decimal_digit'));

            // convert $digit digit. if $digit is 2, 0.01
            $step = ($digit <= 0 ? "0" : "0." . str_repeat("0", $digit - 1) . "1");
            $field->attribute(['type' => 'number', 'step' => $step]);
        }
    }

    protected function getAdminFilterClass()
    {
        return ExmFilter\Between::class;
    }

    /**
     * Set where query for grid filter. If class is "ExmWhere".
     *
     * @param \Illuminate\Database\Query\Builder|\Illuminate\Database\Schema\Builder $query
     * @param mixed $input
     * @return void
     */
    public function getAdminFilterWhereQuery($query, $input)
    {
        $this->getAdminFilterWhereQueryNumber($query, $input);
    }

    protected function setValidates(&$validates)
    {
        $options = $this->custom_column->options;
        $decimal_digit = intval(array_get($options, 'decimal_digit')?? 2);
        $integer_digit =  Define::MAX_FLOAT_PRECISION - $decimal_digit;
        $max_size_number = floatval(str_repeat(9, $integer_digit) . '.' . str_repeat(9, $decimal_digit));
        $min_size_number = -1 * $max_size_number;

        $number_min = max(array_get($options, 'number_min')?? $min_size_number, $min_size_number);
        $number_max = min(array_get($options, 'number_max')?? $max_size_number, $max_size_number);

        // value size
        $validates[] = new Validator\NumberMinRule($number_min);
        $validates[] = new Validator\NumberMaxRule($number_max);

        $validates[] = new Validator\DecimalCommaRule();
    }

    protected function getRemoveValidates()
    {
        return ['integer', 'numeric'];
    }

    /**
     * get cast Options
     */
    protected function getCastOptions()
    {
        if (array_has($this->custom_column, 'options.decimal_digit')) {
            return [DatabaseDataType::TYPE_DECIMAL, true, [
                'length' => 50,
                'decimal_digit' => intval(array_get($this->custom_column, 'options.decimal_digit', 2))
            ]];
        } else {
            return [DatabaseDataType::TYPE_DECIMAL, true, []];
        }
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
        if (is_null($value)) {
            return null;
        }
        return floatval($value);
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
        $this->setCustomColumnOptionFormNumber($form);

        $form->switchbool('percent_format', exmtrans("custom_column.options.percent_format"))
            ->help(exmtrans("custom_column.help.percent_format"));

        $form->number('decimal_digit', exmtrans("custom_column.options.decimal_digit"))
            ->default(2)
            ->min(0)
            ->max(8);
    }
}
