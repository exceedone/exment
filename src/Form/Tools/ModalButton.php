<?php

namespace Exceedone\Exment\Form\Tools;

use Encore\Admin\Facades\Admin;

/**
 * Open Modal button.
 */
class ModalButton
{
    protected $url;
    protected $label;
    protected $btn_class;
    protected $icon;


    public function __construct($options = [])
    {
        foreach ($options as $key => $value) {
            if (property_exists($this, $key)) {
                $this->{$key} = $value;
            }
        }
    }

    public function render()
    {
        // get uuid
        $suuid = short_uuid();
        //Admin::script($this->script($suuid, $label));

        return view('exment::tools.modal-button', [
            'suuid' => $suuid,
            'label' => $this->label ?? null,
            'button_class' => $this->btn_class ?? 'btn-warning',
            'icon' => $this->icon ?? 'fa-share',
            'url' => $this->url
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
