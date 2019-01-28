<?php

namespace Exceedone\Exment\ColumnItems\CustomColumns;

use Exceedone\Exment\ColumnItems\CustomItem;
use Exceedone\Exment\Form\Field;

class Editor extends CustomItem
{
    public function html()
    {
        if (is_null($this->text())) {
            return null;
        }
        return '<div class="show-tinymce">'.preg_replace("/\\\\r\\\\n|\\\\r|\\\\n|\\r\\n|\\r|\\n/", "<br/>", esc_script_tag($this->text())).'</div>';
    }
    
    protected function getAdminFieldClass()
    {
        return Field\Tinymce::class;
    }
}
