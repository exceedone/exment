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

    public function displayText($displayText)
    {
        $this->displayText = $displayText;
    }
    
    /**
     * Render this filed.
     *
     */
    public function render()
    {
        return parent::render()->with([
            'displayText' => $this->displayText
        ]);
    }
}
