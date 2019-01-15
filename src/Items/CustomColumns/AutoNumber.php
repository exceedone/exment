<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Exceedone\Exment\Form\Field;

class AutoNumber extends CustomItem 
{
    protected $required = false;

    protected function getAdminFieldClass(){
        return Field\Display::class;
    }
}
