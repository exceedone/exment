<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Explain extends FormOtherItem
{
    protected function getAdminFieldClass()
    {
        return Field\Description::class;
    }

    /**
     * get html(for display)
     * *Please escape
     */
    public function html()
    {
        // default escapes text
        return esc_html($this->text());
    }
}
