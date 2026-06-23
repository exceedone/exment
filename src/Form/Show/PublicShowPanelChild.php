<?php

namespace Exceedone\Exment\Form\Show;

use ExmentAdminCore\Admin\Show;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Public show panel for child.
 */
class PublicShowPanelChild extends \ExmentAdminCore\Admin\Show\Panel
{
    /**
     * The view to be rendered.
     *
     * @var string
     */
    protected $view = 'exment::public-form.confirmpanel-child';

    /**
     * Render this panel.
     */
    public function render()
    {
        // @phpstan-ignore-next-line
        return parent::render()->with([
        ]);
    }
}
