<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Encore\Admin\Form\Field;

class Html extends FormOtherItem
{
    /**
     * get Text(for display)
     */
    public function text()
    {
        return array_get($this->form_column, 'options.html');
    }

    
    // Not escaping html if html item ----------------------------------------------------
    /**
     * get Text(for display)
     */
    // public function html()
    // {
    //     return $this->text();
    // }

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
