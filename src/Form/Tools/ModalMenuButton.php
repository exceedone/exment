<?php

namespace Exceedone\Exment\Form\Tools;

/**
 * Modal menu button.
 */
class ModalMenuButton
{
    protected $url;
    protected $label;
    protected $expand;
    protected $button_class;
    protected $icon;
    
    public function __construct($url, $options = [])
    {
        $this->url = $url;

        $this->label = array_get($options, 'label');
        $this->button_class = array_get($options, 'button_class', 'btn-primary');
        $this->icon = array_get($options, 'icon', 'fa-check-square');
        $this->expand = array_get($options, 'expand', []);
    }

    public function render()
    {
        return view('exment::tools.modal-menu-button', [
            'ajax' => $this->url,
            'expand' => collect($this->expand)->toJson(),
            'button_class' => $this->button_class,
            'label' => $this->label ?? null,
            'icon' => $this->icon,
        ]);
    }
    
    /**
     * @return string
     */
    public function __toString()
    {
        return $this->render()->render() ?? '';
    }
}
