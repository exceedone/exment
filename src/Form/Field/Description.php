<?php

namespace Exceedone\Exment\Form\Field;

use OpenAdminCore\Admin\Form\Field;

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
     * @param bool $escape
     * @return $this|Description
     */
    public function escape(bool $escape = true)
    {
        $this->escape = $escape;

        return $this;
    }

    public function render()
    {
        // replace offset col-sm and col-md as offset
        $offset = array_get($this->getViewElementClasses(), 'label');
        $offset = str_replace("col-sm-", "offset-sm-", $offset);
        $offset = str_replace("col-md-", "offset-md-", $offset);
        return parent::render()->with(
            [
                'offset' => $offset,
                'escape' => $this->escape,
            ]
        );
    }
}
