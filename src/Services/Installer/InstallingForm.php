<?php
namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\InitializeStatus;

/**
 *
 */
class InstallingForm
{
    use InstallFormTrait;

    public function index()
    {
        return view('exment::install.installing');
    }

    public function post()
    {
        \Artisan::call('key:generate');
        \Artisan::call('passport:keys');
        \Artisan::call('exment:install');

        InstallService::setInitializeStatus(InitializeStatus::INSTALLING);

        return redirect(admin_url('initialize'));
    }
}
