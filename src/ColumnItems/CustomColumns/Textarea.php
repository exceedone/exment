<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Encore\Admin\Form\Field;

class Textarea extends CustomItem
{
    public function html()
    {
        return  preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", esc_script_tag($this->text()));
    }
    protected function getAdminFieldClass()
    {
        return Field\Textarea::class;
    }
}
