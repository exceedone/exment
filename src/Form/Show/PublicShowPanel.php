<?php

namespace Exceedone\Exment\Form\Show;

use Encore\Admin\Show;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;

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

    // @phpstan-ignore-next-line
    protected $action;
    // @phpstan-ignore-next-line
    protected $back_action;
    // @phpstan-ignore-next-line
    protected $confirm_title;
    // @phpstan-ignore-next-line
    protected $confirm_text;
    // @phpstan-ignore-next-line
    protected $relations = [];


    // @phpstan-ignore-next-line
    public function setAction(string $action)
    {
        $this->action = $action;

        return $this;
    }

    // @phpstan-ignore-next-line
    public function setBackAction(string $back_action)
    {
        $this->back_action = $back_action;

        return $this;
    }


    /**
     * Set the value of confirm_title
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setConfirmTitle($confirm_title)
    {
        $this->confirm_title = $confirm_title;

        return $this;
    }

    /**
     * Set the value of confirm_text
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setConfirmText($confirm_text)
    {
        $this->confirm_text = $confirm_text;

        return $this;
    }


    /**
     * Set the value of relations
     *
     * @return  self
     */
    // @phpstan-ignore-next-line
    public function setChildRelationShows($relations)
    {
        $this->relations = $relations;

        return $this;
    }

    /**
     * Render this panel.
     */
    public function render()
    {
        // @phpstan-ignore-next-line
        return parent::render()->with([
            'action' => $this->action,
            'back_action' => $this->back_action,
            'confirm_title' => $this->confirm_title ?? null,
            'confirm_text' => $this->confirm_text ?? null,
            'fieldGroups' => array_get($this->data, 'fieldGroups', []),
            'relations' => $this->relations ?? [],
        ]);
    }
}
