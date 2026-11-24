<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Model\LoginUser;
use Exceedone\Exment\Services\MaintenanceMode;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * Maintenance mode (ISS01396): start and end it, plan it, and choose who gets
 * in and what stops. Only system administrators (Auth\Permission,
 * "maintenance"), only when the feature is used (Features, "maintenance_mode").
 * Each change is in the operation log (admin.log and MaintenanceMode::log).
 */
class MaintenanceController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('maintenance.header'), exmtrans('maintenance.header'), exmtrans('maintenance.description'), 'fa-wrench');
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        static::onlyAdministrators();
        $this->AdminContent($content);
        MaintenanceMode::reset();
        $current = MaintenanceMode::current();
        $settings = MaintenanceMode::settings();
        $startedBy = isset($settings['started_by']) ? LoginUser::find($settings['started_by']) : null;

        $content->row(view('exment::maintenance.index', [
            'current' => $current,
            'operator' => isset($current) && $current['source'] === MaintenanceMode::SOURCE_OPERATOR,
            'settings' => $settings,
            'startedBy' => isset($startedBy) ? $startedBy->user_name : null,
            'locales' => static::locales(),
            'local' => function (?string $at): string {
                return static::toLocal($at);
            },
            'yourIp' => $request->ip(),
            'saveUrl' => admin_url('maintenance'),
            'startUrl' => admin_urls('maintenance', 'start'),
            'endUrl' => admin_urls('maintenance', 'end'),
        ]));
        return $content;
    }

    /**
     * Save the plan and the options (does not start it).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function store(Request $request)
    {
        static::onlyAdministrators();
        list($values, $error) = $this->values($request, false);
        if (isset($error)) {
            return back()->withInput()->withErrors(['maintenance' => $error]);
        }
        MaintenanceMode::save($values);
        MaintenanceMode::log('save', intval(\Exceedone\Exment\Facades\ExmentFacade::user()->id), array_only($values, ['start_at', 'end_at', 'api', 'stop_public', 'stop_schedule', 'stop_queue']));
        admin_toastr(exmtrans('common.message.success_execute'));
        return redirect(admin_url('maintenance'));
    }

    /**
     * Start it now (with the options of the form).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function start(Request $request)
    {
        static::onlyAdministrators();
        list($values, $error) = $this->values($request, true);
        if (isset($error)) {
            return back()->withInput()->withErrors(['maintenance' => $error]);
        }
        MaintenanceMode::start($values, \Exceedone\Exment\Facades\ExmentFacade::user());
        admin_toastr(exmtrans('maintenance.message.started'));
        return redirect(admin_url('maintenance'));
    }

    /**
     * End it now (and its plan). The operator's cannot be ended here.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function end(Request $request)
    {
        static::onlyAdministrators();
        MaintenanceMode::end(\Exceedone\Exment\Facades\ExmentFacade::user());
        MaintenanceMode::reset();
        $current = MaintenanceMode::current();
        if (isset($current) && $current['source'] === MaintenanceMode::SOURCE_OPERATOR) {
            admin_toastr(exmtrans('maintenance.message.operator_remains'), 'warning');
        } else {
            admin_toastr(exmtrans('maintenance.message.ended'));
        }
        return redirect(admin_url('maintenance'));
    }

    /**
     * The system administrators only: also on a site where the roles are not
     * used (Auth\Permission lets everyone in then).
     */
    protected static function onlyAdministrators(): void
    {
        $user = \Exceedone\Exment\Facades\ExmentFacade::user();
        if (!isset($user) || !$user->isAdministrator()) {
            abort(403);
        }
    }

    /**
     * The values of the form, or an error.
     *
     * @return array{0: array<string, mixed>, 1: ?string}
     */
    protected function values(Request $request, bool $now): array
    {
        $start = $now ? null : $this->fromLocal($request->get('start_at'));
        $end = $this->fromLocal($request->get('end_at'));
        if ($start === false || $end === false) {
            return [[], exmtrans('maintenance.error.date')];
        }
        if (isset($end) && $end->lte(Carbon::now())) {
            return [[], exmtrans('maintenance.error.end_passed')];
        }
        // a start that has come: "Start the maintenance now" is the button for it
        // (in a maintenance the start is not asked: it has started)
        if (MaintenanceMode::settings()['active']) {
            $start = null;
        }
        if (isset($start) && $start->lte(Carbon::now())) {
            return [[], exmtrans('maintenance.error.start_passed')];
        }
        if (isset($start, $end) && $end->lte($start)) {
            return [[], exmtrans('maintenance.error.end_before_start')];
        }

        $ips = preg_split('/[\s,]+/', strval($request->get('allow_ips', ''))) ?: [];
        foreach (array_filter($ips) as $ip) {
            if (!MaintenanceMode::validIp($ip)) {
                return [[], exmtrans('maintenance.error.ip', ['ip' => $ip])];
            }
        }

        $messages = [];
        foreach (static::locales() as $locale) {
            $text = $request->input("messages.{$locale}");
            if (is_string($text) && trim($text) !== '') {
                $messages[$locale] = mb_substr($text, 0, 2000);
            }
        }

        return [[
            'start_at' => isset($start) ? $start->toIso8601String() : null,
            'end_at' => isset($end) ? $end->toIso8601String() : null,
            'messages' => $messages,
            'allow_ips' => array_values(array_filter($ips)),
            'api' => strval($request->get('api', MaintenanceMode::API_ADMIN)),
            'stop_public' => boolval($request->get('stop_public')),
            'stop_schedule' => boolval($request->get('stop_schedule')),
            'stop_queue' => boolval($request->get('stop_queue')),
        ], null];
    }

    /**
     * A date and time of the form (in the time zone of the site), null when empty, false when not one.
     *
     * @param mixed $value
     * @return Carbon|false|null
     */
    protected function fromLocal($value)
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return Carbon::parse($value, config('app.timezone'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function toLocal(?string $at, string $format = 'Y-m-d\TH:i'): string
    {
        if (!isset($at) || $at === '') {
            return '';
        }
        try {
            return Carbon::parse($at)->setTimezone(config('app.timezone'))->format($format);
        } catch (\Throwable $e) {
            return '';
        }
    }

    /**
     * The languages of Exment (a message for each).
     *
     * @return string[]
     */
    public static function locales(): array
    {
        $dirs = glob(exment_package_path('resources/lang/*'), GLOB_ONLYDIR) ?: [];
        $locales = array_values(array_filter(array_map('basename', $dirs), function ($locale) {
            return preg_match('/^[a-z]{2}(_[A-Za-z]+)?$/', $locale) === 1;
        }));
        return count($locales) > 0 ? $locales : ['ja', 'en'];
    }
}
