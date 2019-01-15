<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Currency extends Decimal 
{
    public function value(){
        if (boolval(array_get($this->custom_column, 'options.number_format')) 
        && is_numeric($this->value) 
        && !boolval(array_get($this->options, 'disable_number_format')))
        {
            $value = number_format($this->value);
        }else{
            $value = $this->value;
        }

        if(boolval(array_get($this->options, 'disable_currency_symbol'))){
            return $value;
        }
        // get symbol
        $symbol = array_get($this->custom_column, 'options.currency_symbol');
        return getCurrencySymbolLabel($symbol, $value);
    }

    protected function setAdminOptions(&$field, $form_column_options){
        $options = $this->custom_column->options;
        
        // get symbol
        $symbol = array_get($options, 'currency_symbol');
        $field->prepend($symbol);
        $field->attribute(['style' => 'max-width: 200px']);
    }
}
