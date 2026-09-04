<?php

namespace Exceedone\Exment\Form\Tools;

use Illuminate\Contracts\Support\Renderable;

/**
 * Modal menu button.
 */
class ModalMenuButton implements Renderable
{
    // @phpstan-ignore-next-line
    protected $url;
    // @phpstan-ignore-next-line
    protected $label;
    // @phpstan-ignore-next-line
    protected $expand;
    // @phpstan-ignore-next-line
    protected $button_class;
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

    /**
     * Menu Button list
     *
     * @var array
     */
    // @phpstan-ignore-next-line
    protected $menulist = [];

    // @phpstan-ignore-next-line
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
