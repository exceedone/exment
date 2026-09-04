<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Display;

/**
 * Display for view only. Cannot save and update.
 * Use for initOnly option
 */
class InitOnly extends Display
{
    protected $view = 'exment::form.field.init_only';

    // @phpstan-ignore-next-line
    protected $prepareDefault = false;

    // @phpstan-ignore-next-line
    public function prepareDefault()
    {
        $this->prepareDefault = true;
        return $this;
    }

    // @phpstan-ignore-next-line
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
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'prepareDefault'   => $this->prepareDefault,
            'default'          => $this->default,
        ]);
    }
}
