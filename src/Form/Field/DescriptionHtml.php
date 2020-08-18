<?php

namespace Exceedone\Exment\Form\Field;

class DescriptionHtml extends Description
{
    public function render()
    {
        $this->escape(false);
        return parent::render();
    }
}
