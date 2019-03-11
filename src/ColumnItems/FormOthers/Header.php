<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Header extends FormOtherItem
{
    /**
     * get html(for display)
     */
    public function html()
    {
        // default escapes text
        return esc_html($this->text());
    }

    protected function getAdminFieldClass()
    {
        return Field\Header::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        $field->hr();
    }
}
