<?php

namespace Exceedone\Exment\Form\Tools;

use Illuminate\Contracts\Support\Renderable;

/**
 * Modal link. Look like modalbutton.
 */
class ModalLink implements Renderable
{
    // @phpstan-ignore-next-line
    protected $url;
    // @phpstan-ignore-next-line
    protected $label;
    // @phpstan-ignore-next-line
    protected $expand;
    // @phpstan-ignore-next-line
    protected $link_class;
    // @phpstan-ignore-next-line
    protected $icon;
    // @phpstan-ignore-next-line
    protected $html;
    // @phpstan-ignore-next-line
    protected $modal_title;
    // @phpstan-ignore-next-line
    protected $uuid;
    // @phpstan-ignore-next-line
    protected $attributes = [];

    // @phpstan-ignore-next-line
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
