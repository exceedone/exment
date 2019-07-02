<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;
use Encore\Admin\Form\EmbeddedForm;

class NestedEmbeddedForm extends EmbeddedForm
{
    /**
     * Set `elementClass` for fields inside nestedembed fields.
     *
     * @param Field $field
     *
     * @return Field
     */
    protected function formatField(Field $field)
    {
        // copied from parent formatField

        $jsonKey = $field->column();

        $elementName = $errorKey = [];

        if (is_array($jsonKey)) {
            foreach ($jsonKey as $index => $name) {
                $elementName[$index] = "{$this->column}[$name]";
                $errorKey[$index] = "{$this->column}.$name";
            }
        } else {
            $elementName = "{$this->column}[$jsonKey]";
            $errorKey = "{$this->column}.$jsonKey";
        }

        $field->setElementName($elementName)
            ->setErrorKey($errorKey);

        // set class
        $column = $field->column();

        $elementClass = [];

        // get key (before "[" and split)
        $key = explode("[", $this->column)[0];

        if (is_array($column)) {
            foreach ($column as $k => $name) {
                $elementClass[$k] = $key. "_" . $name;
            }
        } else {
            $elementClass = [$key. "_" . $column, $column];
        }

        return $field
            ->setElementClass($elementClass);
    }

    /**
     * Get  script of template.
     *
     * @return array
     */
    public function getScripts()
    {
        $scripts = [];

        /* @var Field $field */
        foreach ($this->fields() as $field) {
            //when field render, will push $script to Admin
            $field->render();

            /*
             * Get and remove the last script of Admin::$script stack.
             */
            if ($field->getScript()) {
                $scripts[] = array_pop(Admin::$script);
            }
        }

        return implode("\r\n", $scripts);
    }
}
