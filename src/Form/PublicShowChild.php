<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Show\Field;
use Illuminate\Support\Collection;

class PublicShowChild extends \Encore\Admin\Show
{
    /**
     * Initialize panel.
     */
    protected function initPanel()
    {
        $this->panel = new PublicShowPanelChild($this);
    }
}
