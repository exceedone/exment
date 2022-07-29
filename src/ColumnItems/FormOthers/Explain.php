<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Form\Field;
use Exceedone\Exment\Enums\FormLabelType;

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
    public function _html($v)
    {
        // default escapes text
        return replaceBreakEsc($this->_text($v));
    }

    protected function setAdminOptions(&$field)
    {
        parent::setAdminOptions($field);
        // not escape because always calls escape in "_html" function
        $field->escape(false);

        // if not HORIZONTAL, set style attributes
        if ($this->getLabelType() != FormLabelType::HORIZONTAL) {
            $field->attribute('style', 'margin-left: 15px;');
        }
        if ($this->getLabelType() == FormLabelType::HIDDEN) {
            $field->enableLabel();
        }
    }
}
