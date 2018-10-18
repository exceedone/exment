<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\HasMany as AdminHasMany;

/**
 * Class HasMany.
 */
class HasMany extends AdminHasMany
{
    /**
     * Render the `HasMany` field.
     *
     * @throws \Exception
     *
     * @return \Illuminate\View\View
     */
    public function render()
    {
        // specify a view to render.
        $this->view = $this->views[$this->viewMode];

        $form = $this->buildNestedForm($this->column, $this->builder);
        list($template, $script) = $this->getTemplateHtmlAndScript($form);

        $this->setupScript($script);

        $grandParent = get_parent_class(get_parent_class($this));
        return $grandParent::render()->with([
            'forms'        => $this->buildRelatedForms(),
            'template'     => $template,
            'relationName' => $this->relationName,
        ]);
    }

    
    /**
     * TODO: I don't know the best way
     * set html and script. It has bug about nested
     */
    protected function getTemplateHtmlAndScript($form){
        list($template, $script) = $form->getTemplateHtmlAndScript();

        // re-set $script
        $scripts = [];
        foreach($form->fields() as $field){
            // when NestedEmbeds item, get NestedEmbeds's getScript()
            if (method_exists($field, 'getScript')) {
                $scripts[] = $field->getScript();
            }
        }

        return [$template, implode("\r\n", $scripts)];
    }
}
