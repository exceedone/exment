<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Form\Field;

class Yesno extends CustomItem 
{
    public function text(){
        return boolval($this->value) ? 'YES' : 'NO';
    }

    protected function getAdminFieldClass(){
        return Field\SwitchBoolField::class;
    }
}
