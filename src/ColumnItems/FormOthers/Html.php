<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;

class Html extends FormOtherItem
{
    /**
     * get Text(for display)
     */
    // @phpstan-ignore-next-line
    protected function _text($v)
    {
        return array_get($this->form_column_options, 'html');
    }


    /**
     * get Text(for display)
     */
    // @phpstan-ignore-next-line
    public function _html($v)
    {
        // Sanitize with HTML Purifier to prevent stored XSS while keeping rich formatting (JVN #05318392)
        return html_clean($this->_text($v));
    }

    /**
     * get column name
     */
    // @phpstan-ignore-next-line
    public function name()
    {
        return $this->form_column->id;
    }

    // @phpstan-ignore-next-line
    protected function getAdminFieldClass()
    {
        return Field\Html::class;
    }
}
