<?php

namespace Exceedone\Exment\Form\Show;

use OpenAdminCore\Admin\Show;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

/**
 * Public show panel for child.
 */
class PublicShowPanelChild extends \OpenAdminCore\Admin\Show\Panel
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
        /** @phpstan-ignore-next-line Need laravel-admin php doc. */
        return parent::render()->with([
        ]);
    }
}
