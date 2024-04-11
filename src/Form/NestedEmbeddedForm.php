<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Admin;
use Encore\Admin\Form\Field;

class NestedEmbeddedForm extends EmbeddedForm
{
    protected $data_key;

    /**
     * EmbeddedForm constructor.
     *
     * @param string $column
     */
    public function __construct($column, $data_key = null)
    {
        $this->data_key = $data_key;

        parent::__construct($column);
    }

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
        $key = explode("[", $this->column)[0];

        if (is_array($column)) {
            foreach ($column as $k => $name) {
                $elementClass[$k] = $key. "_" . $name;
            }
        } else {
            $elementClass = [$key. "_" . $column, $column];
            if (isset($this->data_key) && is_numeric($this->data_key)) {
                $elementClass[] = "rownum_" . $this->data_key;
            }
        }

        return $field
            ->setElementClass($elementClass);
    }

    /**
     * Get script of template.
     *
     * @return string|array
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

        return $scripts;
//        return implode("\r\n", $scripts);
    }

    /**
     * Set original values for fields.
     *
     * @param array|string $data
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
     * Set original data for each field.
     *
     * @param string $key
     *
     * @return void
     */
    protected function setFieldOriginalValue($key)
    {
        $this->fields->each(function (Field $field) use ($key) {
            if ($field->column() === $key) {
                $field->setOriginal($this->original);
                return false;
            }
        });
    }

    /**
     * Get data key.
     *
     * @return int|string
     */
    public function getDataKey()
    {
        return $this->data_key;
    }
}
