<?php

namespace Exceedone\Exment\Form\Tools;

/**
 * Modal menu button. Calling as Ajax
 */
class ModalTileAjaxMenuButton extends ModalTileMenuButton
{
    // @phpstan-ignore-next-line
    public function __construct($url, $options = [])
    {
        parent::__construct($options);

        $this->url = $url;
    }

    /**
     * Get tile html
     */
    // @phpstan-ignore-next-line
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
