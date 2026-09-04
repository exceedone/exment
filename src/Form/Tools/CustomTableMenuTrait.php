<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\Define;
use Encore\Admin\Facades\Admin;

/**
 * Custom Table Menu
 */
trait CustomTableMenuTrait
{
    // @phpstan-ignore-next-line
    protected $page_name;
    // @phpstan-ignore-next-line
    protected $page_name_sub;
    // @phpstan-ignore-next-line
    protected $custom_table;

    // @phpstan-ignore-next-line
    protected function getItems()
    {
        $items = [];
        foreach (Define::GRID_CHANGE_PAGE_MENULIST as $menu) {
            // if same page, skip
            // if ($this->page_name == array_get($menu, 'name') && !array_has($menu, 'buttons')) {
            //     continue;
            // }

            // check menu using role
            // if this page_name is table and grid, check role
            if ($this->page_name == 'table' && !isset($this->custom_table)) {
                // if user dont't has role system
                /** @phpstan-ignore-next-line */
                if (!Admin::user()->hasPermission(array_get($menu, 'roles'))) {
                    continue;
                }
            } elseif (array_get($menu, 'name') == 'view' && !$this->custom_table->hasViewPermission()) {
                continue;
            } else {
                // if user dont't has role as table
                if (!$this->custom_table->hasPermission(array_get($menu, 'roles'))) {
                    continue;
                }
            }

            $url = str_replace(':id', $this->custom_table->id, array_get($menu, 'href'));
            $url = str_replace(':table_name', $this->custom_table->table_name, $url);


            // get buttons
            $buttons = collect(array_get($menu, 'buttons', []))
                // ->filter(function($button){
                //     return array_get($button, 'name') != $this->page_name_sub;
                // })
                ->map(function ($button) {
                    return [
                        'icon' => array_get($button, 'icon'),
                        /** @phpstan-ignore-next-line */
                        'label' => exmtrans(array_get($button, 'exmtrans')),
                        'href' => admin_url(str_replace(':id', $this->custom_table->id, array_get($button, 'href'))),
                    ];
                });


            $items[] = [
                // @phpstan-ignore-next-line
                'href' => admin_url($url),
                'icon' => array_get($menu, 'icon'),
                /** @phpstan-ignore-next-line */
                'header' => exmtrans(array_get($menu, 'exmtrans')),
                /** @phpstan-ignore-next-line */
                'description' => exmtrans(array_get($menu, 'description')),
                'buttons' => $buttons,
            ];
        }

        return $items;
    }
}
