<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Validator;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Enums\DatabaseDataType;

class Integer extends CustomItem
{
    use NumberTrait;

    protected function _text($v)
    {
        if (is_null($v)) {
            return null;
        }

        if (boolval(array_get($this->custom_column, 'options.number_format'))
            && is_numeric($v)
            && !boolval(array_get($this->options, 'disable_number_format'))) {
            return number_format($v);
        }
        return $v;
    }

    public function saving()
    {
        $rmv = rmcomma($this->value);
        if (!isset($rmv)) {
            return null;
        }
        return intval($rmv);
    }

    protected function getAdminFieldClass()
    {
        return Field\Number::class;
    }
    
    protected function setAdminOptions(&$field)
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

        $field->attribute(['type' => 'number']);
        $field->callbackValue(function ($value) {
            return rmcomma($value);
        });
    }

    protected function setValidates(&$validates)
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

        $validates[] = new Validator\IntegerCommaRule;
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
        return[DatabaseDataType::TYPE_INTEGER, true, []];
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
        return intval($value);
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

        $form->switchbool('updown_button', exmtrans("custom_column.options.updown_button"))
            ->help(exmtrans("custom_column.help.updown_button"))
            ;
    }
}
