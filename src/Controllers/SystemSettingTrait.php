<?php

namespace Exceedone\Exment\Controllers;

use Exceedone\Exment\Enums\Permission;

trait SystemSettingTrait
{
    protected function setBaseSettingButton($target)
    {
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            return;
        }

        $this->setButton($target, view('exment::tools.button', [
            'href' => admin_url('system'),
            'label' => exmtrans('common.basic_setting'),
            'icon' => 'fa-cog',
        ]));
    }
    
    protected function setAdvancedSettingButton($target)
    {
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            return;
        }

        $this->setButton($target, view('exment::tools.button', [
            'href' => admin_url('system?advanced=1'),
            'label' => exmtrans('common.detail_setting'),
            'icon' => 'fa-cogs',
        ]));
    }
    
    protected function setApiSettingButton($target)
    {
        if (!\Exment::user()->hasPermission(Permission::AVAILABLE_API)) {
            return;
        }
        
        $this->setButton($target, view('exment::tools.button', [
            'href' => admin_url('api_setting'),
            'label' => exmtrans('api.header'),
            'icon' => 'fa-code-fork',
        ]));
    }

    protected function setLoginSettingButton($target)
    {
        if (!\Exment::user()->hasPermission(Permission::SYSTEM)) {
            return;
        }
        
        $this->setButton($target, view('exment::tools.button', [
            'href' => admin_url('login_setting'),
            'label' => exmtrans('login.header'),
            'icon' => 'fa-sign-in',
        ]));
    }

    protected function setButton($target, $view)
    {
        if ($target instanceof \Encore\Admin\Widgets\Box) {
            $target->tools($view);
        } elseif ($target instanceof \Encore\Admin\Grid\Tools) {
            $target->prepend($view);
        }
    }
}
