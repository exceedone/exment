<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Header extends FormOtherItem
{
    /**
     * get html(for display)
     */
    // @phpstan-ignore-next-line
    protected function _html($v)
    {
        // default escapes text
        return esc_html($this->_text($v));
    }

    // @phpstan-ignore-next-line
    protected function getAdminFieldClass()
    {
        return Field\Header::class;
    }

    // @phpstan-ignore-next-line
    protected function setAdminOptions(&$field)
    {
        parent::setAdminOptions($field);
        // not escape because always calls escape in "_html" function
        $field->escape(false);

        if (boolval(array_get($this->form_column_options, 'append_hr'))) {
            $field->hr();
        }
    }
}
