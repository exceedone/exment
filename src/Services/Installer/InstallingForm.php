<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\InitializeStatus;

/**
 *
 */
class InstallingForm
{
    use EnvTrait;

    public function index()
    {
        return view('exment::install.installing');
    }

    public function post()
    {
        try {
            $inputs = [
                'APP_DEBUG' => boolval(request()->get('APP_DEBUG')) ? 'true' : 'false'
            ];
            $this->setEnv($inputs);
            InstallService::forgetInputParams();
        } catch (\Exception $ex) {
            return back()->withInput()->withErrors([
                'install_error' => exmtrans('install.error.cannot_write_env'),
            ]);
        }

        \Artisan::call('key:generate');
        \Artisan::call('passport:keys');
        \Artisan::call('exment:install');

        InstallService::setInitializeStatus(InitializeStatus::INSTALLING);

        admin_toastr(exmtrans('install.help.install_success'));
        return redirect(admin_url('initialize'));
    }
}
