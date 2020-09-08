<?php

namespace Exceedone\Exment\Form\Widgets;

use Illuminate\Contracts\Support\Renderable;

/**
 */
class SelectItemBox implements Renderable
{
    protected $iframe_url;
    protected $target_class;
    protected $widgetmodal_uuid;
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
     * @param string $iframe_url
     * @param array $items
     */
    public function __construct($iframe_url, $target_class, $widgetmodal_uuid, array $items)
    {
        $this->iframe_url = $iframe_url;
        $this->target_class = $target_class;
        $this->widgetmodal_uuid = $widgetmodal_uuid;
        $this->items = $items;
    }

    public function render()
    {
        return view('exment::widgets.selectitem-box', [
            'iframe_url' => $this->iframe_url,
            'target_class' => $this->target_class ?? null,
            'widgetmodal_uuid' => $this->widgetmodal_uuid ?? null,
            'items' => $this->items,
        ])->render();
    }
}
