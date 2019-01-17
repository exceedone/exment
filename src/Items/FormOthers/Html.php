<?php

namespace Exceedone\Exment\Items\FormOthers;

use Exceedone\Exment\Items\FormOtherItem;
use Encore\Admin\Form\Field;

class Html extends FormOtherItem 
{
    protected function getAdminFieldClass(){
        return Field\Html::class;
    }
}
