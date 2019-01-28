<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;

class Email extends CustomItem
{
    protected function getAdminFieldClass()
    {
        return Field\Email::class;
    }
}
