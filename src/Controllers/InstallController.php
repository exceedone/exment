<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Exceedone\Exment\Services\Installer\InstalledState;
use Exceedone\Exment\Services\Installer\InstallRunner;
use Exceedone\Exment\Services\Installer\InstallService;

class InstallController extends Controller
{
    /**
     * Index interface.
     *
     * @return Content
     */
    public function index(Request $request)
    {
        return InstallService::index();
    }

    /**
     * reset interface.
     *
     * @param Request $request
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Http\RedirectResponse|\Illuminate\Routing\Redirector
     */
    public function reset(Request $request)
    {
        InstalledState::refuseIfInstalled();

        // the install of the last step: only one that failed, only by the browser that started
        // it, only by the form (POST). An install going on is never reset.
        if (!is_null(InstallRunner::read())) {
            if ($request->isMethod('post') && static::isOwner($request) && InstallRunner::forgetFailed()) {
                InstallService::forgetInitializeStatus();
                InstallService::forgetInputParams();
                return redirect(admin_urls('install'))->withCookie(InstallRunner::forgetCookie());
            }
            return redirect(admin_urls('install'));
        }

        InstallService::forgetInitializeStatus();
        InstallService::forgetInputParams();

        return redirect(admin_urls('install'));
    }

    /**
     * submit
     * @param Request $request
     */
    // @phpstan-ignore-next-line
    public function post(Request $request)
    {
        \Exment::setTimeLimitLong();
        return InstallService::post();
    }

    /**
     * One step of the install (InstallRunner): a few seconds of work, then its progress.
     * Called by the screen of the progress of the browser that started the install, again
     * and again until it is done.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function step(Request $request)
    {
        InstalledState::refuseIfInstalled();
        if (!is_null($response = $this->refuseOthers($request))) {
            return $response;
        }

        InstallService::setSettingTmp();
        $progress = (new InstallRunner())->step(boolval($request->get('retry')));
        $succeeded = $progress['status'] === InstallRunner::STATUS_SUCCEEDED;
        if ($succeeded) {
            InstallService::forgetInitializeStatus();
            admin_toastr(exmtrans('install.help.install_success'));
        }

        // without script (the button of the screen): the screen again
        $response = $request->expectsJson()
            ? response()->json($this->withUrls($progress))
            : redirect($succeeded ? admin_url('initialize') : admin_url('install'));
        return $succeeded ? $response->withCookie(InstallRunner::forgetCookie()) : $response;
    }

    /**
     * The progress of the install, without work (the screen reads it while a step is working).
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function status(Request $request)
    {
        InstalledState::refuseIfInstalled();
        if (!is_null($response = $this->refuseOthers($request))) {
            return $response;
        }
        return response()->json($this->withUrls(InstallRunner::status()));
    }

    /**
     * Another browser than the one that started the install: only that an install is going on.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse|null null: go on
     */
    protected function refuseOthers(Request $request)
    {
        if (is_null(InstallRunner::read()) || static::isOwner($request)) {
            return null;
        }
        if (!$request->expectsJson()) {
            return redirect(admin_url('install'));
        }
        return response()->json(['status' => 'in_progress'], 403);
    }

    protected static function isOwner(Request $request): bool
    {
        $token = $request->cookie(InstallRunner::COOKIE);
        return InstallRunner::isOwner(is_string($token) ? $token : null);
    }

    /**
     * @param array<string, mixed> $progress
     * @return array<string, mixed>
     */
    protected function withUrls(array $progress): array
    {
        if ($progress['status'] === InstallRunner::STATUS_SUCCEEDED) {
            $progress['redirect'] = admin_url('initialize');
        } elseif ($progress['status'] === 'none') {
            $progress['redirect'] = admin_url('install');
        }
        return $progress;
    }
}
