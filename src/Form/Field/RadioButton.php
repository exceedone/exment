<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Radio;
use Encore\Admin\Validator\HasOptionRule;

class RadioButton extends Radio
{
    protected $view = 'exment::form.field.radiobutton';

    protected $addEmpty = false;

    /**
     * Set addEmpty option.
     *
     * @param boolean $addEmpty
     *
     * @return $this
     */
    public function addEmpty($addEmpty = true)
    {
        $this->addEmpty = $addEmpty;
        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function render()
    {
        $this->addVariables(['add_empty' => $this->addEmpty]);

        return parent::render();
    }
}
