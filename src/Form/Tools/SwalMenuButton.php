<?php

namespace Exceedone\Exment\Form\Tools;

use Illuminate\Contracts\Support\Renderable;

/**
 * Modal menu button.
 */
class SwalMenuButton implements Renderable
{
    protected $label;

    /**
     * Menu Button list
     *
     * @var array
     */
    protected $menulist = [];

    protected $attributes = [];

    public function __construct(array $menulist = [], array $options = [])
    {
        $this->menulist = $menulist;
        $this->label = array_get($options, 'label') ?? trans('admin.menu');
    }

    public function render()
    {
        if (!is_nullorempty($this->menulist)) {
            $this->attributes['data-toggle'] = 'dropdown';
            $this->attributes['aria-haspopup'] = true;
            $this->attributes['aria-expanded'] = false;
        }

        return view('exment::tools.swal-menu-button', [
            'button_label' => $this->label,
            'menulist' => $this->menulist,
        ])->render();
    }
}
