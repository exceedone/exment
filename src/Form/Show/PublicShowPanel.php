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

    protected $action;
    protected $back_action;
    protected $confirm_title;
    protected $confirm_text;
    protected $relations = [];


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
     * Set the value of confirm_title
     *
     * @return  self
     */
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
        /** @phpstan-ignore-next-line Need laravel-admin php doc. */
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
