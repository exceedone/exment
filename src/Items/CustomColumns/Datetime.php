<?php

namespace Exceedone\Exment\Items\CustomColumns;

use Exceedone\Exment\Items\CustomItem;
use Encore\Admin\Form\Field;

class Datetime extends Date 
{
    protected function getAdminFieldClass(){
        return Field\DateTime::class;
    }
    
    protected function setAdminFilterOptions(&$filter){
        $filter->datetime();
    }
}
