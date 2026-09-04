<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Header extends Field\Display
{
    protected $view = 'exment::form.field.header';

    // @phpstan-ignore-next-line
    protected $no;

    // @phpstan-ignore-next-line
    protected $hr;

    /**
     * Whether escape
     *
     * @var boolean
     */
    protected $escape = true;

    // @phpstan-ignore-next-line
    public function __construct($label)
    {
        $this->no = 4;
        $this->hr = false;

        $this->label = $label;
    }

    /**
     *
     * @return $this|mixed
     */
    public function hr()
    {
        $this->hr = true;
        return $this;
    }

    /**
     *
     * @return $this|mixed
     */
    // @phpstan-ignore-next-line
    public function no($no)
    {
        $this->no = $no;
        return $this;
    }

    /**
     * Toggle escape
     *
     * @param bool $escape
     * @return $this|Header
     */
    public function escape(bool $escape = true)
    {
        $this->escape = $escape;

        return $this;
    }

    public function render()
    {
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'no' => $this->no,
            'hr' => $this->hr,
            'escape' => $this->escape,
            'headerLabel' => $this->label(),
        ]);
    }
}
