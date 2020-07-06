<?php

namespace Exceedone\Exment\Form\Widgets;

use Illuminate\Contracts\Support\Renderable;

/**
 */
class SelectItemBox implements Renderable
{
    protected $html;
    protected $target_class;
    protected $items;

    /**
     * $items : [
     *     [
     *         'name': 'select', // this select item name
     *         'label': 'xxxx',
     *         'icon': 'fa-user', //default
     *         'color': '#000000', //default
     *         'background_color': '#FFFFFF', //default
     *         'multiple': true, // if true, can set multiple item
     *         'items': [[  // selected items
     *             'value': '1',
     *             'label': 'admin',
     *             'icon': 'fa-user', //if especially
     *             'color': '#000000', //if especially
     *             'background_color': '#FFFFFF', //if especially
     *         ], [
     *             'value': '2',
     *             'label': 'user',
     *             'icon': 'fa-user', //if especially
     *             'color': '#000000', //if especially
     *             'background_color': '#FFFFFF', //if especially
     *         ]],
     *     ]
     * ]
     *
     * @param string $html
     * @param array $items
     */
    public function __construct($html, $target_class, array $items)
    {
        $this->html = $html;
        $this->target_class = $target_class;
        $this->items = $items;
    }

    public function render()
    {
        return view('exment::widgets.selectitem-box', [
            'html' => $this->html,
            'target_class' => $this->target_class ?? null,
            'items' => $this->items,
        ])->render();
    }
}
