<?php

namespace Exceedone\Exment\Form\Show;

/**
 * Show for public form.
 * Contains hasManys. If has hasMany, set "setChildRelationShows"
 */
class PublicShow extends \Exceedone\Exment\Form\Show
{
    /**
     * Initialize panel.
     */
    // @phpstan-ignore-next-line
    protected function initPanel()
    {
        $this->panel = new PublicShowPanel($this);
    }


    // @phpstan-ignore-next-line
    public function setAction(string $action)
    {
        $this->panel->setAction($action);

        return $this;
    }

    // @phpstan-ignore-next-line
    public function setBackAction(string $back_action)
    {
        $this->panel->setBackAction($back_action);

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
        $this->panel->setConfirmTitle($confirm_title);

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
        $this->panel->setConfirmText($confirm_text);

        return $this;
    }

    /**
     * Set child relation shows
     *
     * @return self
     */
    // @phpstan-ignore-next-line
    public function setChildRelationShows($childRelationShows)
    {
        $this->panel->setChildRelationShows($childRelationShows);

        return $this;
    }
}
