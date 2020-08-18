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

    
    /**
     * get Text(for display)
     */
    public function html()
    {
        // Not escaping html whether html item
        return $this->text();
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
