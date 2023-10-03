<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\Permission;

class SystemChangePageMenu extends ModalTileMenuButton
{
    public function __construct()
    {
        parent::__construct([
            'label' => exmtrans("system.system_header"),
            'icon' => 'fa-cog',
            'button_class' => 'btn-default',
        ]);
        $this->modal_title = exmtrans("system.system_header");
    }

    /**
     * @return string|null
     */
    public function render()
    {
        $items = $this->getMenuItems();

        // if no menu, return
        if (count($items) == 0) {
            return null;
        }

        $this->groups = [[
            'items' => $items
        ]];

        return parent::render();
    }

    protected function getMenuItems()
    {
        return collect([
            [
                'href' => admin_url('system'),
                'icon' => 'fa-cog',
                'header' => exmtrans('system.system_header'),
                'description' => exmtrans('system.system_description'),
                'permission' => \Exment::user()->hasPermission(Permission::SYSTEM),

                'buttons' => [
                    [
                        'icon' => 'fa-cog',
                        'label' => exmtrans('common.basic_setting'),
                        'href' => admin_url('system'),
                    ],
                    [
                        'icon' => 'fa-cogs',
                        'label' => exmtrans('common.detail_setting'),
                        'href' => admin_urls_query('system', ['advanced' => '1']),
                    ],
                ],
            ],
            [
                'href' => admin_url('table'),
                'icon' => 'fa-table',
                'header' => exmtrans('custom_table.header'),
                'description' => exmtrans('custom_table.description'),
                'permission' => \Exment::user()->hasPermission(Permission::CUSTOM_TABLE),
            ],
            [
                'href' => admin_url('role_group'),
                'icon' => 'fa-user-secret',
                'header' => exmtrans("role_group.header"),
                'description' => exmtrans('role_group.description'),
                'permission' => \Exment::user()->hasPermission(Permission::AVAILABLE_ACCESS_ROLE_GROUP),
            ],
            [
                'href' => admin_url('workflow'),
                'icon' => 'fa-share-alt',
                'header' => exmtrans('workflow.header'),
                'description' => exmtrans('workflow.description'),
                'permission' => \Exment::user()->hasPermission(Permission::WORKFLOW),
            ],
            [
                'href' => admin_url('api_setting'),
                'icon' => 'fa-code-fork',
                'header' => exmtrans('api.header'),
                'description' => exmtrans('api.description'),
                'permission' => System::api_available() && \Exment::user()->hasPermission(Permission::AVAILABLE_API),
            ],
            [
                'href' => admin_url('login_setting'),
                'icon' => 'fa-sign-in',
                'header' => exmtrans('login.header'),
                'description' => exmtrans('login.description'),
                'permission' => \Exment::user()->hasPermission(Permission::SYSTEM),
            ],
        ])->filter(function ($item) {
            return $item['permission'];
        })->toArray();
    }

    public function __toString()
    {
        return $this->render();
    }
}
