<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\NestedEmbeddedForm;

class NestedEmbeds extends Embeds
{
    protected $view = 'exment::form.field.embeds';

    protected $nestedForm;

    protected $relationName;


    /**
     * Create a new HasMany field instance.
     *
     * @param string $column
     * @param array  $arguments
     */
    public function __construct($column, $arguments = [])
    {
        parent::__construct($column, $arguments);
    }

    /**
     * Get NestedEmbeddedForm.
     *
     * @return NestedEmbeddedForm
     */
    protected function buildEmbeddedForm()
    {
        if (!isset($this->nestedForm)) {
            $form = new NestedEmbeddedForm($this->elementName);
            $this->nestedForm = $this->setFormField($form);
        }
        return $this->nestedForm;
    }

    public function setRelationName($relationName)
    {
        $this->relationName = $relationName;

        return $this;
    }

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
            return json_decode($this->value, true);
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
        if (isset($script)) {
            $this->script = $script;
        }

        return $render;
    }
}
