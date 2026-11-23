<?php

namespace Exceedone\Exment\Services\Installer;

use Exceedone\Exment\Enums\InitializeStatus;

/**
 *
 */
class InstallingForm
{
    use EnvTrait;

    // @phpstan-ignore-next-line
    public function index()
    {
        return view('exment::install.installing');
    }

    /**
     * SESSION_PATH: the folder of the site when it is under one (https://example.com/exment),
     * so that its cookie is not sent to the other sites of the domain. Kept when .env has it.
     *
     * @return array<string, string>
     */
    public static function sessionPath(?string $basePath = null): array
    {
        if (!is_null(env('SESSION_PATH'))) {
            return [];
        }
        $basePath = rtrim($basePath ?? request()->getBasePath(), '/');
        return $basePath === '' ? [] : ['SESSION_PATH' => $basePath];
    }

    // @phpstan-ignore-next-line
    public function post()
    {
        // Merge session DB inputs with the APP_DEBUG flag from this form,
        // then write everything to .env in one shot (EnvService deduplicates).
        // the value in front of the URLs of Exment (empty: none, the default from 7.2.1)
        $prefix = \Exceedone\Exment\Services\LegacyAdminRedirect::normalizePrefix(request()->get('ADMIN_ROUTE_PREFIX'));
        if (is_null($prefix)) {
            return back()->withInput()->withErrors([
                'ADMIN_ROUTE_PREFIX' => exmtrans('install.error.admin_route_prefix'),
            ]);
        }

        try {
            $merged = array_merge(InstallService::getInputParams(), [
                'APP_DEBUG' => boolval(request()->get('APP_DEBUG')) ? 'true' : 'false',
                'ADMIN_ROUTE_PREFIX' => $prefix,
            ], static::sessionPath());
            $this->setEnv($merged);
            // with a config cache (php artisan config:cache), .env is not read: the
            // database and the URL prefix just written would not apply
            if (app()->configurationIsCached()) {
                \Artisan::call('config:clear');
            }
            InstallService::setSettingTmp();
            InstallService::forgetInputParams();
        } catch (\Exception $ex) {
            \Log::error('Exment install: cannot write .env: ' . $ex->getMessage());
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
        // .env is read again from the next request: go to the URL with the prefix just written
        return redirect(url($prefix === '' ? 'initialize' : $prefix . '/initialize'));
    }
}
