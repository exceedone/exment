<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Time extends Date 
{
    protected function getAdminFieldClass(){
        return Field\Time::class;
    }
}
