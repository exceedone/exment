<?php

namespace Exceedone\Exment\Form\Show;

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
