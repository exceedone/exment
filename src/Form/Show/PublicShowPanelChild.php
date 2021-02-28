<?php

namespace Exceedone\Exment\Form\Show;

use Encore\Admin\Show;

/**
 * Public show panel for child. 
 */
class PublicShowPanelChild extends \Encore\Admin\Show\Panel
{
    /**
     * The view to be rendered.
     *
     * @var string
     */
    protected $view = 'exment::public-form.confirmpanel-child';

    /**
     * Render this panel.
     *
     * @return string
     */
    public function render()
    {
        return parent::render()->with([
        ]);
    }
}
