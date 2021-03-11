<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Display;

/**
 * Display for view only. Cannot save and update.
 * Use for viewonly option (form).
 */
class ViewOnly extends Display
{
    protected $view = 'exment::form.field.view_only';

    protected $prepareDefault = false;

    public function prepareDefault()
    {
        $this->prepareDefault = true;
        return $this;
    }

    public function prepare($value)
    {
        // Even if set value, return always default.
        return $this->default;
    }
    
    /**
     * {@inheritdoc}
     */
    public function render()
    {
        return parent::render()->with([
            'prepareDefault'   => $this->prepareDefault,
            'default'          => is_array($this->default)? json_encode($this->default): $this->default,
        ]);
    }
}
