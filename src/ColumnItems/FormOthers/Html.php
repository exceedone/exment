<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Html extends FormOtherItem
{
    /**
     * get Text(for display)
     */
    protected function _text($v)
    {
        return array_get($this->form_column_options, 'html');
    }


    /**
     * get Text(for display)
     */
    public function _html($v)
    {
        // Not escaping html whether html item
        return $this->_text($v);
    }

    /**
     * get column name
     */
    public function name()
    {
        return $this->form_column->id;
    }

    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }
}
