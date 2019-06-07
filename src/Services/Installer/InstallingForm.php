<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Model\System;

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
