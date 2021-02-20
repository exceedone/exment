<?php

namespace Exceedone\Exment\Form;

use Encore\Admin\Show\Field;
use Illuminate\Support\Collection;

class PublicShow extends \Encore\Admin\Show
{
    /**
     * Initialize panel.
     */
    protected function initPanel()
    {
        $this->panel = new PublicShowPanel($this);
    }
    

    public function setAction(string $action)
    {
        $this->panel->setAction($action);

        return $this;
    }

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
    public function setConfirmText($confirm_text)
    {
        $this->panel->setConfirmText($confirm_text);

        return $this;
    }
}
