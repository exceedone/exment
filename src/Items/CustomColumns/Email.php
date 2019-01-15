<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Email extends CustomItem 
{
    protected function getAdminFieldClass(){
        return Field\Email::class;
    }
}
