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
        // an install stopped (an error, or left): it is forgotten, not while a step is working
        if (InstallRunner::inProgress() && !InstallRunner::forget()) {
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
     * Called by the screen of the progress again and again until it is done.
     *
     * @return \Illuminate\Http\JsonResponse|\Illuminate\Http\RedirectResponse
     */
    public function step(Request $request)
    {
        // a site installed before (not by this install): closed
        if (is_null(InstallRunner::read())) {
            InstalledState::refuseIfInstalled();
        }

        InstallService::setSettingTmp();
        $progress = (new InstallRunner())->step(boolval($request->get('retry')));
        if ($progress['status'] === InstallRunner::STATUS_SUCCEEDED) {
            InstallService::forgetInitializeStatus();
            admin_toastr(exmtrans('install.help.install_success'));
        }

        // without script (the button of the screen): the screen again
        if (!$request->expectsJson()) {
            return redirect($progress['status'] === InstallRunner::STATUS_SUCCEEDED ? admin_url('initialize') : admin_url('install'));
        }
        return response()->json($this->withUrls($progress));
    }

    /**
     * The progress of the install, without work (the screen reads it while a step is working).
     */
    public function status(Request $request): \Illuminate\Http\JsonResponse
    {
        if (is_null(InstallRunner::read())) {
            InstalledState::refuseIfInstalled();
        }
        return response()->json($this->withUrls(InstallRunner::status()));
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
