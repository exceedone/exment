<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Widgets\Form as WidgetForm;
use Encore\Admin\Facades\Admin;

class ModalInnerForm extends WidgetForm
{
    public function getScript() {
        return collect($this->fields)->map(function ($field) {
            /* @var Field $field  */
            return $field->getScript();
        })->filter()->values()->toArray();
    }
}
