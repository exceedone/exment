<?php

namespace Exceedone\Exment\Items\FormOthers;

use Exceedone\Exment\Items\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Description extends FormOtherItem 
{
    protected function getAdminFieldClass(){
        return Field\Description::class;
    }
}
