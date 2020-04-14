<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Illuminate\Contracts\Support\Renderable;
use Encore\Admin\Layout\Content;
use Encore\Admin\Widgets\Box;
use Encore\Admin\Widgets\Form as WidgetForm;
use Exceedone\Exment\Enums\CustomValueAutoShare;
use Exceedone\Exment\Enums\FilterSearchType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Exceedone\Exment\Enums\Login2FactorProviderType;
use Exceedone\Exment\Enums\MailKeyName;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\SystemVersion;
use Exceedone\Exment\Enums\SystemColumn;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Exceptions\NoMailTemplateException;
use Exceedone\Exment\Exment;
use Exceedone\Exment\Form\Widgets\InfoBox;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Model\RoleGroup;
use Exceedone\Exment\Services\Auth2factor\Auth2factorService;
use Exceedone\Exment\Services\Installer\InitializeFormTrait;
use Exceedone\Exment\Services\NotifyService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Validator;

trait SystemSettingTrait
{
    protected function setBaseSettingButton($target)
    {
        if(!\Exment::user()->hasPermission(Permission::SYSTEM)){
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
        if(!\Exment::user()->hasPermission(Permission::SYSTEM)){
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
        if(!\Exment::user()->hasPermission(Permission::AVAILABLE_API)){
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
        if(!\Exment::user()->hasPermission(Permission::SYSTEM)){
            return;
        }
        
        $this->setButton($target, view('exment::tools.button', [
            'href' => admin_url('login_setting'),
            'label' => exmtrans('login.header'),
            'icon' => 'fa-sign-in',
        ]));
    }

    protected function setButton($target, $view){
        if($target instanceof \Encore\Admin\Widgets\Box){
            $target->tools($view);
        }elseif($target instanceof \Encore\Admin\Grid\Tools){
            $target->prepend($view);
        }
    }
}
