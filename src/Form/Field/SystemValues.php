<?php

namespace Exceedone\Exment\Form\Field;

use Encore\Admin\Form\Field;
use Exceedone\Exment\Form\SystemValuesTrait;

class SystemValues extends Field
{
    use SystemValuesTrait;

    public function render()
    {
        return $this->renderSystemItem($this->form->model());
    }
}
