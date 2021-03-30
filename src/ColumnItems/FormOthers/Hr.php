<?php

namespace Exceedone\Exment\ColumnItems\FormOthers;

use Exceedone\Exment\ColumnItems\FormOtherItem;
use Exceedone\Exment\Enums\FormLabelType;
use Encore\Admin\Form\Field;

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

    protected function setAdminOptions(&$field)
    {
        $field_label_type = $this->getLabelType();
        // get form info
        switch ($field_label_type) {
            case FormLabelType::HORIZONTAL:
                break;
            case FormLabelType::VERTICAL:
                $field->disableHorizontal();
                break;
            case FormLabelType::HIDDEN:
                $field->disableHorizontal();
                $field->disableLabel();
                break;
        }

    }
}
