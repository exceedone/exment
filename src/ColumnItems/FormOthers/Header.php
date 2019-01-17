<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Header extends FormOtherItem 
{
    protected function getAdminFieldClass(){
        return Field\Header::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options){
        $field->hr();
    }
}
