<?php

namespace Exceedone\Exment\Form\Show;

use Illuminate\Contracts\Support\Renderable;

/**
 * Public show for relation.
 * This class manages hasmany items.
 * And contains this block's name.
 */
class PublicShowRelation implements Renderable
{
    /**
     * Panel constructor.
     */
    public function __construct()
    {
    }

    /**
     * The view to be rendered.
     *
     * @var string
     */
    protected $view = 'exment::public-form.confirmpanel-relation';

    protected $title;

    protected $children = [];

    /**
     * Get the value of title
     */
    public function getTitle()
    {
        return $this->title;
    }

    /**
     * Set the value of title
     *
     * @return  self
     */
    public function setTitle($title)
    {
        $this->title = $title;

        return $this;
    }

    /**
     * Get the value of children
     */
    public function getChildren()
    {
        return $this->children;
    }

    /**
     * Add the value of children
     *
     * @return  self
     */
    public function addChildren($children)
    {
        $this->children[] = $children;

        return $this;
    }

    /**
     * Render this panel.
     *
     * @return string
     */
    public function render()
    {
        return view($this->view, [
            'title' => $this->title,
            'children' => $this->children,
        ]);
    }
}
