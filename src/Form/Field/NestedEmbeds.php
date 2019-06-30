<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\NestedEmbeddedForm;

class NestedEmbeds extends Embeds
{
    protected $view = 'exment::form.field.embeds';

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
        $form = new NestedEmbeddedForm($this->elementName);
        return $this->setFormField($form);
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
