<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Encore\Admin\Form\Field;

class Html extends FormOtherItem
{
    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }
}
