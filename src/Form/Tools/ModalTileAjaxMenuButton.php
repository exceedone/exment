<?php

namespace Exceedone\Exment\Form\Tools;

/**
 * Modal menu button. Calling as Ajax
 */
class ModalTileAjaxMenuButton extends ModalTileMenuButton
{
    public function __construct($url, $options = [])
    {
        parent::__construct($options);

        $this->url = $url;
    }

    /**
     * Get tile html
     */
    public function ajaxHtml()
    {
        return $this->html();
    }

    public function render()
    {
        $this->html = null;

        return parent::render();
    }
}
