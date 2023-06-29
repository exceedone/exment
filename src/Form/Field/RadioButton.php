<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field\Radio;

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
    public function addEmpty(bool $addEmpty)
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

    /**
     * Set as Modal.
     *
     * @return $this
     */
    public function asModal()
    {
        return $this;
    }
}
