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

    /**
     * Render as a grid-toolbar tool instead of a floated button.
     *
     * The default `float-end` markup is what every non-grid caller (form
     * headers, box tools) relies on for right alignment, but floats lay
     * the buttons out in reverse DOM order. Inside the data-grid toolbar
     * that made the phone layout (flex, DOM order) the mirror image of
     * the PC one, and left no gap between the last unfloated tool and
     * the first floated one. Grid callers set this so the button joins
     * the normal flow as `.exm-grid-tool` and the DOM can simply be
     * written in display order.
     *
     * @var bool
     */
    protected $grid_tool = false;

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
     * Join the grid toolbar flow instead of floating right.
     *
     * @return $this
     */
    public function gridTool()
    {
        $this->grid_tool = true;

        return $this;
    }

    /**
     * @return string|null
     */
    public function render()
    {
        if (!is_nullorempty($this->menulist)) {
            $this->attributes['data-bs-toggle'] = 'dropdown';
            $this->attributes['aria-haspopup'] = true;
            $this->attributes['aria-expanded'] = false;
        }

        return view('exment::tools.modal-menu-button', [
            'uuid' => $this->uuid,
            'ajax' => $this->url,
            'expand' => collect($this->expand)->toJson(),
            'grid_tool' => $this->grid_tool,
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
