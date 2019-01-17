<?php

namespace Exceedone\Exment\Items\FormOthers;

use Exceedone\Exment\Items\FormOtherItem;
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
