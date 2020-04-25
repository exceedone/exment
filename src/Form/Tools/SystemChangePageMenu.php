<?php

namespace Exceedone\Exment\Form\Tools;

use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\Permission;
use Encore\Admin\Grid\Tools\AbstractTool;

class SystemChangePageMenu extends AbstractTool
{
    protected $key;

    public function __construct($key)
    {
        $this->key = $key;
    }

    public function render()
    {
        $menulist = collect($this->getMenuItems())->filter(function($menu){
            if(!$menu['permission']){
                return false;
            }
            if($menu['key'] == $this->key){
                return false;
            }

            return true;
        });
        // if no menu, return
        if (count($menulist) == 0) {
            return null;
        }

        return view('exment::tools.menu-button', [
            'menulist' => $menulist,
            'button_label' => exmtrans("system.system_header"),
        ])->render();
    }

    protected function getMenuItems(){
        return[
            [
                'key' => 'basic_setting',
                'href' => admin_url('system'),
                'exmtrans' => 'common.basic_setting',
                'icon' => 'fa-cog',
                'permission' => \Exment::user()->hasPermission(Permission::SYSTEM),
            ], 
            [
                'key' => 'detail_setting',
                'href' => admin_urls_query('system', ['advanced' => '1']),
                'exmtrans' => 'common.detail_setting',
                'icon' => 'fa-cogs',
                'permission' => \Exment::user()->hasPermission(Permission::SYSTEM),
            ], 
            [
                'key' => 'api_setting',
                'href' => admin_url('api_setting'),
                'exmtrans' => 'api.header',
                'icon' => 'fa-code-fork',
                'permission' => System::api_available() && \Exment::user()->hasPermission(Permission::AVAILABLE_API),
            ], 
            [
                'key' => 'login_setting',
                'href' => 'login_setting',
                'exmtrans' => 'login.header',
                'icon' => 'fa-sign-in',
                'permission' => \Exment::user()->hasPermission(Permission::SYSTEM),
            ], 
        ];
    }

    public function __toString(){
        return $this->render();
    }
}
