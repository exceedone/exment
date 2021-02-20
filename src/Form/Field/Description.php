<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Description extends Field\Display
{
    protected $view = 'exment::form.field.description';

    /**
     * Whether escape
     *
     * @var boolean
     */
    protected $escape = true;

    public function __construct($label)
    {
        $this->label = $label;
    }

    /**
     * Toggle escape
     *
     * @var boolean
     */
    public function escape(bool $escape)
    {
        $this->escape = $escape;

        return $this;
    }

    public function render()
    {
        return parent::render()->with(
            [
                'offset' => str_replace("col-md-", "col-md-offset-", array_get($this->getViewElementClasses(), 'label')),
                'escape' => $this->escape,
            ]
        );
    }
}
