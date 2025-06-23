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

    protected $customScripts = [];

    public function addCustomScriptToArray(array $scripts)
    {
        foreach ($scripts as $script) {
            $this->customScripts[] = $script;
        }
        return $this;
    }

    /**
     * Get script each fields
     *
     * @return array
     */
    public function getScript()
    {
        $fieldScripts = collect($this->fields)->map(function ($field) {
            return $field->getScript();
        })->filter()->values()->toArray();

        return array_merge($fieldScripts, $this->customScripts);
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
