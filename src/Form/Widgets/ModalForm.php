<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Widgets\Form as WidgetForm;

class ModalForm extends WidgetForm
{
    /**
     * Available buttons.
     *
     * @var array
     */
    protected $buttons = [];

    public function getScript()
    {
        return collect($this->fields)->map(function ($field) {
            /* @var Field $field  */
            return $field->getScript();
        })->filter()->values()->toArray();
    }
}
