<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\NestedEmbeddedForm;

class NestedEmbeds extends Embeds
{
    protected $view = 'exment::form.field.embeds';

    protected $nestedForm;

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
    protected function buildEmbeddedForm()
    {
        if (!isset($this->nestedForm)) {
            $form = new NestedEmbeddedForm($this->elementName);
            $this->nestedForm = $this->setFormField($form);
        }
        return $this->nestedForm;
    }

    protected function getRules($input = [])
    {
        $rules = [];
        foreach ($this->buildEmbeddedForm()->fields() as $field) {
            if (!$fieldRules = $field->getRules($input)) {
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
