<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Hr extends FormOtherItem
{
    /**
     * get Text(for display)
     */
    public function _html($v)
    {
        // Not escaping html whether html item
        return "<hr />";
    }

    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }
}
