<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Validator;

class Text extends CustomItem
{
    public function saving()
    {
        if (is_nullorempty($this->value)) {
            return;
        }
        return strval($this->value);
    }

    protected function getAdminFieldClass()
    {
        return Field\Text::class;
    }
    
    protected function setValidates(&$validates, $form_column_options)
    {
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'string_length')) {
            $validates[] = 'max:'.array_get($options, 'string_length');
        }
        
        // value type
        $validates[] = new Validator\StringNumericRule();
    }
}
