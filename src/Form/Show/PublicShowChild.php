<?php

namespace Exceedone\Exment\Form\Show;

use Encore\Admin\Show\Field;
use Illuminate\Support\Collection;

class PublicShowChild extends \Exceedone\Exment\Form\Show
{
    /**
     * Initialize panel.
     */
    protected function initPanel()
    {
        $this->panel = new PublicShowPanelChild($this);
    }
}
