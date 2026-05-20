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
        // Merge session DB inputs with the APP_DEBUG flag from this form,
        // then write everything to .env in one shot (EnvService deduplicates).
        try {
            $merged = array_merge(InstallService::getInputParams(), [
                'APP_DEBUG' => boolval(request()->get('APP_DEBUG')) ? 'true' : 'false',
            ]);
            $this->setEnv($merged);
            InstallService::setSettingTmp();
            InstallService::forgetInputParams();
        } catch (\Exception $ex) {
            return back()->withInput()->withErrors([
                'install_error' => exmtrans('install.error.cannot_write_env'),
            ]);
        }

        if (!\ExmentDB::canConnection()) {
            return back()->withInput()->withErrors([
                'install_error' => exmtrans('install.error.database_canconnection'),
            ]);
        }

        try {
            \Artisan::call('key:generate');
            \Artisan::call('passport:keys');
            $exitCode = \Artisan::call('exment:install');
            if ($exitCode !== 0) {
                $output = trim(\Artisan::output());
                \Log::error('exment:install failed (exit ' . $exitCode . '): ' . $output);
                return back()->withInput()->withErrors([
                    'install_error' => $output ?: exmtrans('install.error.database_canconnection'),
                ]);
            }
        } catch (\Exception $ex) {
            \Log::error('Exment install commands failed: ' . $ex->getMessage());
            return back()->withInput()->withErrors([
                'install_error' => $ex->getMessage(),
            ]);
        }

        InstallService::setInitializeStatus(InitializeStatus::INSTALLING);

        admin_toastr(exmtrans('install.help.install_success'));
        return redirect(admin_url('initialize'));
    }
}
