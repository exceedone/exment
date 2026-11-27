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
        // the install is going on (or stopped with an error): its progress
        if (!is_null($state = InstallRunner::read())) {
            // another browser: only that an install is going on
            $token = request()->cookie(InstallRunner::COOKIE);
            if (!InstallRunner::isOwner(is_string($token) ? $token : null)) {
                return view('exment::install.in_progress');
            }
            return view('exment::install.progress', [
                'resetUrl' => admin_urls('install', 'reset'),
                'progress' => InstallRunner::toProgress($state),
                'stepUrl' => admin_urls('install', 'step'),
                'statusUrl' => admin_urls('install', 'status'),
                'initializeUrl' => admin_url('initialize'),
                'pollMs' => max(500, intval(config('exment.install_poll_ms', 1500))),
            ]);
        }
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
        // the install: the config is not cached yet, .env is read
        // @phpstan-ignore larastan.noEnvCallsOutsideOfConfig
        if (!is_null(env('SESSION_PATH'))) {
            return [];
        }
        $basePath = rtrim($basePath ?? request()->getBasePath(), '/');
        return $basePath === '' ? [] : ['SESSION_PATH' => $basePath];
    }

    // @phpstan-ignore-next-line
    public function post()
    {
        // the installer does not run again on an installed site
        InstalledState::refuseIfInstalled();

        // the install is started already (a second click, a reload of the POST): its progress
        if (!is_null(InstallRunner::read())) {
            return redirect(admin_url('install'));
        }

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
            // --force: on a production site key:generate asks, and stops without a terminal
            \Artisan::call('key:generate', ['--force' => true]);
            \Artisan::call('passport:keys');
            // the rest (the files, the migrations, the first data) in steps: the screen of the
            // progress calls install/step until it is done (InstallRunner). One long request
            // would be stopped by a proxy with a short timeout (504 of nginx after 60 seconds).
            // the token of this browser: only it runs the install (a cookie, see InstallRunner)
            $token = InstallRunner::start();
        } catch (\Exception $ex) {
            \Log::error('Exment install commands failed: ' . $ex->getMessage());
            return back()->withInput()->withErrors([
                'install_error' => $ex->getMessage(),
            ]);
        }

        InstallService::setInitializeStatus(InitializeStatus::INSTALLING);

        // .env is read again from the next request: the progress, at the URL with the prefix just written
        $response = redirect()->to(url($prefix === '' ? 'install' : $prefix . '/install'));
        if (!is_null($token)) {
            $response->headers->setCookie(InstallRunner::cookie($token));
        }
        return $response;
    }
}
