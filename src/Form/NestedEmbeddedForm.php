<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;

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
        $errPrefix = Field::getDotName($this->column);

        if (is_array($jsonKey)) {
            foreach ($jsonKey as $index => $name) {
                $elementName[$index] = "{$this->column}[$name]";
                $errorKey[$index] = "$errPrefix.$name";
            }
        } else {
            $elementName = "{$this->column}[$jsonKey]";
            $errorKey = "$errPrefix.$jsonKey";
        }

        $field->setElementName($elementName)
            ->setErrorKey($errorKey);

        // set class
        $column = $field->column();

        $elementClass = [];

        // get key (before "[" and split)
        $splits = explode("[", $this->column);
        $key = $splits[0];
        if (count($splits) > 1) {
            $row_no = explode("]", $splits[1])[0];
        }

        if (is_array($column)) {
            foreach ($column as $k => $name) {
                $elementClass[$k] = $key. "_" . $name;
            }
        } else {
            $elementClass = [$key. "_" . $column, $column];
            if (isset($row_no) && is_numeric($row_no)) {
                $elementClass[] = "rownum_$row_no";
            }
        }

        return $field
            ->setElementClass($elementClass);
    }

    /**
     * Get script of template.
     *
     * @return string
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

    /**
     * Set original values for fields.
     *
     * @param array $data
     *
     * @return $this
     */
    public function setOriginal($data)
    {
        if (empty($data)) {
            $data = [];
        }

        if (is_string($data)) {
            $data = json_decode($data, true);
        }

        $this->original = $data;

        return $this;
    }

    /**
     * Get column of current form.
     *
     * @return Collection
     */
    public function column()
    {
        return $this->column;
    }
}
