<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

/**
 * change field. If user select other input select, change input field
 */
class ChangeField extends Field
{
    protected $view = 'exment::form.field.changefield';

    protected function getElementClass()
    {
        if (preg_match('/(^[^\[\]]+)\[([^\[\]]+)\]\[([^\[\]]+)\]$/', $this->elementName, $array_result)) {
            array_shift($array_result);
            $array_result[1] = 'rowno-'.$array_result[1];
            return $array_result;
        }
        return [];
    }

    public function render()
    {
        // $viewClass = $this->getViewElementClasses();
        $field = getCustomField($this->data);

        if (isset($field)) {
            if (boolval(array_get($this->attributes, 'required'))) {
                if (!($field instanceof \Exceedone\Exment\Form\Field\SwitchField)) {
                    $field->required();
                }
            }
            $field->setWidth(12, 0)->setLabelClass(['hidden'])->setElementClass(['w-100'])->attribute(['style' => 'max-width:999999px']);
            $field->value($this->value);
            $field->setElementName($this->elementName)
                ->setErrorKey($this->getErrorKey())
                ->setElementClass($this->getElementClass());
            $field->forgetHelp();
            $view = $field->render();
            $this->script = $field->getScript();
            return $view;
        } else {
            return parent::render();
        }
    }
}
