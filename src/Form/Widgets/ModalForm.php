<?php

namespace Exceedone\Exment\Form\Widgets;

use Encore\Admin\Widgets\Form as WidgetForm;

/**
 * @method mixed hasManyTable($tableName, $columnName, $closure)
 * @method mixed description($label)
 * @method mixed descriptionHtml($description)
 * @method mixed switchbool($optionName, $label)
 * @method mixed modalAttribute($attribute, $modal)
 * @method mixed modalHeader($label)
 * @method mixed progressTracker()
 */
class ModalForm extends WidgetForm
{
    /**
     * Available buttons.
     *
     * @var array
     */
    protected $buttons = [];

    /**
     * Get script each fields
     *
     * @return array
     */
    public function getScript()
    {
        return collect($this->fields)->map(function ($field) {
            /* @var Field $field  */
            return $field->getScript();
        })->filter()->values()->toArray();
    }

    /**
     * Render the form.
     *
     * @return string
     */
    public function render()
    {
        $this->disablePjax();

        return parent::render();
    }
}
