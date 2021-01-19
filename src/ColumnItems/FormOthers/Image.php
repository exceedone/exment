<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Encore\Admin\Form\Field;

class Image extends FormOtherItem
{
    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }

    /**
     * get html(for display)
     * *Please escape
     */
    public function _html($v)
    {
        $image = array_get($this->form_column, 'options.image');
        // default escapes text
        return esc_html($this->_text($v));
    }
    
    protected function setAdminOptions(&$field, $form_column_options)
    {
    }
}
