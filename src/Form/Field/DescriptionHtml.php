<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;

class DescriptionHtml extends Description
{
    public function render()
    {
        $this->escape(false);
        return parent::render();
    }
}
