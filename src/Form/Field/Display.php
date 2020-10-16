<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class Display extends Field\Display
{
    protected $view = 'exment::form.field.display';

    /**
     * Display text
     *
     * @var mixed
     */
    protected $displayText;

    /**
     * Display class
     *
     * @var mixed
     */
    protected $displayClass;

    /**
     * escape value
     *
     * @var bool
     */
    protected $escape = true;

    public function displayText($displayText)
    {
        $this->displayText = $displayText;

        return $this;
    }
    
    public function displayClass($displayClass)
    {
        $this->displayClass = $displayClass;

        return $this;
    }
    
    public function escape($escape = true)
    {
        $this->escape = $escape;

        return $this;
    }
    
    /**
     * Render this filed.
     *
     */
    public function render()
    {
        if ($this->displayText instanceof \Closure) {
            $this->displayText = $this->displayText->call($this, $this->value);
        }

        return parent::render()->with([
            'displayText' => $this->displayText,
            'displayClass' => $this->displayClass,
            'escape' => $this->escape,
        ]);
    }
}
