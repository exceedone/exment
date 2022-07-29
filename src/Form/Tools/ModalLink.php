<?php

namespace Exceedone\Exment\Form\Tools;

use Illuminate\Contracts\Support\Renderable;

/**
 * Modal link. Look like modalbutton.
 */
class ModalLink implements Renderable
{
    protected $url;
    protected $label;
    protected $expand;
    protected $link_class;
    protected $icon;
    protected $html;
    protected $modal_title;
    protected $uuid;
    protected $attributes = [];

    public function __construct($url, $options = [])
    {
        $this->url = $url;

        $this->label = array_get($options, 'label');
        $this->modal_title = array_get($options, 'modal_title');
        $this->link_class = array_get($options, 'link_class');
        $this->icon = array_get($options, 'icon', 'fa-check-square');
        $this->expand = array_get($options, 'expand', []);
        $this->attributes = array_get($options, 'attributes', []);

        $this->uuid = make_uuid();
    }

    public function render()
    {
        return view('exment::tools.modal-link', [
            'uuid' => $this->uuid,
            'ajax' => $this->url,
            'expand' => collect($this->expand)->toJson(),
            'link_class' => $this->link_class,
            'label' => $this->label ?? null,
            'icon' => $this->icon,
            'html' => $this->html,
            'modal_title' => $this->modal_title,
            'attributes' => \Exment::formatAttributes($this->attributes)
        ])->render();
    }
}
