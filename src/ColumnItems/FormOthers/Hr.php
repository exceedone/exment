<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Hr extends FormOtherItem
{
    /**
     * get Text(for display)
     */
    // @phpstan-ignore-next-line
    public function _html($v)
    {
        // Not escaping html whether html item
        return "<hr />";
    }

    // @phpstan-ignore-next-line
    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }
}
