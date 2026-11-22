<?php

namespace Exceedone\Exment\Controllers;

use ExmentAdminCore\Admin\Layout\Content;
use Exceedone\Exment\Enums\SystemRequireCalledType;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\SystemRequire\SystemRequireList;
use Exceedone\Exment\Services\SystemRequire\Tuning\AutoTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\ConfigCacheTuning;
use Exceedone\Exment\Services\SystemRequire\Tuning\DebugLogTuning;
use Illuminate\Http\Request;

/**
 * Tuning (ISS00414): what would make this site faster, and buttons for what can be
 * changed safely from the screen. Only system administrators (Auth\Permission, "tuning").
 *
 * The buttons only do what can be undone: make or remove the config cache, turn off
 * the system performance log. Each press is kept in the operation log (admin.log).
 */
class TuningController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('tuning.header'), exmtrans('tuning.header'), exmtrans('tuning.description'), 'fa-tachometer');
    }

    /**
     * Whether buttons may change a setting of the whole server (the config cache).
     * Turn it off where several sites share the code (a multi-tenant server).
     */
    public static function serverActionsAllowed(): bool
    {
        return boolval(config('exment.tuning_server_actions', true));
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        $this->AdminContent($content);
        $content->row(view('exment::tuning.index', [
            'checkResult' => SystemRequireList::make(SystemRequireCalledType::TUNING),
            // the system requirements (PHP's limits, the permissions...), moved here from the system setting (ISS02828)
            'requireResult' => SystemRequireList::make(SystemRequireCalledType::WEB),
            'serverActions' => static::serverActionsAllowed(),
            'actionUrl' => admin_urls('tuning', 'action'),
            'autoItems' => (new AutoTuning())->available(),
            // what it did last (at the setup or here)
            'autoLast' => AutoTuning::last(),
        ]));
        return $content;
    }

    /**
     * Run one of the buttons.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function action(Request $request)
    {
        $action = (string) $request->get('action');
        $serverWide = in_array($action, [ConfigCacheTuning::ACTION_CACHE, ConfigCacheTuning::ACTION_CLEAR], true);
        if ($serverWide && !static::serverActionsAllowed()) {
            abort(403);
        }

        // the tuning Exment can do by itself, the chosen items (AutoTuning)
        if ($action === 'auto') {
            // kept from the sites (SaaS) or the server-wide buttons off: not here
            if (!AutoTuning::enabled()) {
                abort(403);
            }
            $result = (new AutoTuning())->apply((array) $request->get('auto_tuning', []));
            if (count($result['failed']) > 0) {
                admin_toastr(exmtrans('tuning.action_failed'), 'error');
            } else {
                admin_toastr(exmtrans('tuning.action_done'));
            }
            return redirect(admin_url('tuning'));
        }

        try {
            switch ($action) {
                case ConfigCacheTuning::ACTION_CACHE:
                    // the same routine as AutoTuning (keeps the application of this request)
                    AutoTuning::cacheConfig();
                    break;
                case ConfigCacheTuning::ACTION_CLEAR:
                    \Artisan::call('config:clear');
                    break;
                case DebugLogTuning::ACTION:
                    System::logging_toggle_available(false);
                    System::clearCache();
                    break;
                default:
                    abort(404);
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $ex) {
            throw $ex;
        } catch (\Throwable $ex) {
            \Log::error($ex);
            admin_toastr(exmtrans('tuning.action_failed'), 'error');
            return redirect(admin_url('tuning'));
        }

        admin_toastr(exmtrans('tuning.action_done'));
        return redirect(admin_url('tuning'));
    }
}
