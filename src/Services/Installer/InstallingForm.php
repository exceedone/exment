<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Model\Define;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\CustomColumn;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Enums\RoleType;
use Exceedone\Exment\Enums\JoinedOrgFilterType;
use Encore\Admin\Widgets\Form as WidgetForm;

/**
 * 
 */
class InstallingForm
{
    use InstallFormTrait;

    public function index(){
        
        return view('exment::install.installing');

    }

    public function post(){
        \Artisan::call('exment:install');

        System::installed(true);

        return redirect(admin_url('initialize'));   
    }
}
