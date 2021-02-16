<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Show;

/**
 * Public show panel. 
 * *Contains create form*
 */
class PublicShowPanel extends \Encore\Admin\Show\Panel
{
    /**
     * The view to be rendered.
     *
     * @var string
     */
    protected $view = 'exment::public-form.confirmpanel';

    protected $action;
    protected $back_action;


    public function setAction(string $action)
    {
        $this->action = $action;

        return $this;
    }

    public function setBackAction(string $back_action)
    {
        $this->back_action = $back_action;

        return $this;
    }
    

    /**
     * Render this panel.
     *
     * @return string
     */
    public function render()
    {
        return parent::render()->with([
            'action' => $this->action,
            'back_action' => $this->back_action,
        ]);
    }
}
