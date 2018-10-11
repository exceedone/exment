<?php

namespace Exceedone\Exment\Form\Field;
use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\NestedEmbeddedForm;
use Exceedone\Exment\Form\Field\Embeds;

class NestedEmbeds extends Embeds
{
    protected $view = 'admin::form.embeds';

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

        $form->setParent($this->form);

        call_user_func($this->builder, $form);

        $form->fill($this->getEmbeddedData());

        return $form;
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
        if(isset($script)){
            $this->script = $script;
        }

        return $render;
    }
}