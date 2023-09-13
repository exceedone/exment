<?php

namespace Exceedone\Exment\Form\Field;

use Exceedone\Exment\Form\NestedEmbeddedForm;
use Illuminate\Support\Arr;

class NestedEmbeds extends Embeds
{
    protected $view = 'exment::form.field.embeds';

    protected $nestedForm;

    protected $relationName;

    protected $data_key;

    /**
     * Create a new HasMany field instance.
     *
     * @param string $column
     * @param array  $arguments
     */
    public function __construct($column, $arguments = [])
    {
        $this->data_key = Arr::get($arguments, 0, '');
        parent::__construct($column, array_slice($arguments, 1));
    }

    /**
     * Get NestedEmbeddedForm.
     *
     * @return NestedEmbeddedForm
     */
    protected function buildEmbeddedForm()
    {
        if (!isset($this->nestedForm)) {
            $form = new NestedEmbeddedForm($this->elementName, $this->data_key);
            $this->nestedForm = $this->setFormField($form);
        }
        return $this->nestedForm;
    }

    public function setRelationName($relationName)
    {
        $this->relationName = $relationName;

        return $this;
    }

    /**
     * @return array|string
     */
    protected function getRules()
    {
        $rules = [];
        foreach ($this->buildEmbeddedForm()->fields() as $field) {
            if (!$fieldRules = $field->getRules()) {
                continue;
            }
            $column = $field->column();
            $rules[$column] = $fieldRules;
        }
        return $rules;
    }

    public function getAttributes()
    {
        $attributes = [];
        foreach ($this->buildEmbeddedForm()->fields() as $field) {
            $attributes[$this->column . '.'. $field->column] = $field->label();
        }
        /** @phpstan-ignore-next-line Need to fix laravel-admin */
        return $attributes;
    }


    /**
     * Get data for Embedded form.
     *
     * Normally, data is obtained from the database.
     *
     * When the data validation errors, data is obtained from session flash.
     *
     * @return array
     */
    protected function getEmbeddedData()
    {
        $keyName = "{$this->relationName}.{$this->column}";
        if ($old = old($keyName)) {
            return $old;
        }

        if (empty($this->value)) {
            return [];
        }

        if (is_string($this->value)) {
            return json_decode_ex($this->value, true);
        }

        return (array) $this->value;
    }


    /**
     * Render the form.
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        $render = parent::render();
        $script = $this->buildEmbeddedForm()->getScripts();
        if (!is_nullorempty($script)) {
            $this->script = $script;
        }

        return $render;
    }
}
