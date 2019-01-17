<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;

class Text extends CustomItem 
{
    protected function getAdminFieldClass(){
        return Field\Text::class;
    }
    
    protected function setValidates(&$validates){
        $options = $this->custom_column->options;
        
        // value size
        if (array_get($options, 'string_length')) {
            $validates[] = 'max:'.array_get($options, 'string_length');
        }
    }
}
