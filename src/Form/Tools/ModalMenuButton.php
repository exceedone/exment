<?php

namespace Exceedone\Exment\Form\Tools;

use Illuminate\Contracts\Support\Renderable;

/**
 * Modal menu button.
 */
class ModalMenuButton implements Renderable
{
    protected $url;
    protected $label;
    protected $expand;
    protected $button_class;
    protected $icon;
    protected $html;
    protected $modal_title;
    protected $uuid;
    protected $attributes = [];

    /**
     * Menu Button list
     *
     * @var array
     */
    protected $menulist = [];

    public function __construct($url, $options = [])
    {
        $this->url = $url;

        $this->label = array_get($options, 'label');
        $this->button_class = array_get($options, 'button_class', 'btn-primary');
        $this->icon = array_get($options, 'icon', 'fa-check-square');
        $this->expand = array_get($options, 'expand', []);

        $this->uuid = make_uuid();
    }

    /**
     * @return string|null
     */
    public function render()
    {
        if (!is_nullorempty($this->menulist)) {
            $this->attributes['data-toggle'] = 'dropdown';
            $this->attributes['aria-haspopup'] = true;
            $this->attributes['aria-expanded'] = false;
        }

        return view('exment::tools.modal-menu-button', [
            'uuid' => $this->uuid,
            'ajax' => $this->url,
            'expand' => collect($this->expand)->toJson(),
            'button_class' => $this->button_class,
            'label' => $this->label ?? null,
            'icon' => $this->icon,
            'html' => $this->html,
            'modal_title' => $this->modal_title,
            'menulist' => $this->menulist,
            'attributes' => \Exment::formatAttributes($this->attributes)

        ])->render();
    }
}
