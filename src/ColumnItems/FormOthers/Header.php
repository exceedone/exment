<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Header extends FormOtherItem
{
    /**
     * get html(for display)
     */
    protected function _html($v)
    {
        // default escapes text
        return esc_html($this->_text($v));
    }

    protected function getAdminFieldClass()
    {
        return Field\Header::class;
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
        // not escape because always calls escape in "_html" function 
        $field->escape(false);
    }
}
