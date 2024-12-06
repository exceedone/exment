<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use OpenAdmin\Admin\Form\Field;

class Hidden extends CustomItem
{
    protected function getAdminFieldClass()
    {
        return Field\Hidden::class;
    }
}
