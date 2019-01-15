<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Form\Field;

class Editor extends CustomItem 
{
    public function html(){
        if(is_null($this->value)){
            return null;
        }
        return '<div class="show-tinymce">'.preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>" , esc_script_tag($this->value)).'</div>';
    }
    
    protected function getAdminFieldClass(){
        return Field\Tinymce::class;
    }
}
