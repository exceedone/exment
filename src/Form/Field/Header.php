<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Header extends Field\Display
{
    protected $view = 'exment::form.field.header';

    protected $no;

    protected $hr;

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
    public function no($no)
    {
        $this->no = $no;
        return $this;
    }

    public function render()
    {
        return parent::render()->with([
            'no' => $this->no,
            'hr' => $this->hr,
        ]);
    }
}
