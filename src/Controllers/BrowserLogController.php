<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\BrowserLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\BrowserLogs;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The log of the browsers (ISS02904): the rows a browser sends (webapi/browser_log), and their page
 * in the log management, found by the user, the time and the kind (the system administrators and
 * the role groups with "Audit"; the settings, the system administrators).
 */
class BrowserLogController extends AdminControllerBase
{
    public const PAGE_ROWS = 100;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('browser_log.header'), exmtrans('browser_log.header'), exmtrans('browser_log.description'), 'fa-desktop');
    }

    /**
     * The rows of a browser (its script, every few seconds).
     *
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $user = \Exment::user();
        if (!BrowserLogs::active($user)) {
            abort(403);
        }
        $events = json_decode(strval($request->input('events')), true);
        $env = json_decode(strval($request->input('env')), true);
        BrowserLogs::accept($user, $events, $env);
        return response('', 204);
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        if (!Permission::canAudit()) {
            abort(403);
        }
        $this->AdminContent($content);
        $filter = $this->filter($request);
        $page = max(1, intval($request->get('page', 1)));
        $rows = collect();
        $more = false;
        if (BrowserLog::tableExists()) {
            $rows = $this->query($filter)->orderByDesc('id')->offset(($page - 1) * static::PAGE_ROWS)->limit(static::PAGE_ROWS + 1)->get();
            $more = $rows->count() > static::PAGE_ROWS;
            $rows = $rows->take(static::PAGE_ROWS);
        }
        $user = \Exment::user();
        $users = getModelName(SystemTableName::USER)::withTrashed()->whereIn('id', $rows->pluck('base_user_id')->filter()->unique()->all())->get()->keyBy('id');
        $query = array_filter($filter, function ($v) {
            return $v !== null && $v !== '';
        });

        $content->row(view('exment::log_management.browser', [
            'rows' => $rows,
            'filter' => $filter,
            'kinds' => BrowserLog::KINDS,
            'page' => $page,
            'prevUrl' => $page > 1 ? admin_urls_query('log_management', 'browser', $query + ['page' => $page - 1]) : null,
            'nextUrl' => $more ? admin_urls_query('log_management', 'browser', $query + ['page' => $page + 1]) : null,
            'url' => admin_urls('log_management', 'browser'),
            'logUrl' => admin_url('log_management'),
            'featureUrl' => admin_url('feature'),
            'active' => BrowserLogs::active(),
            'isSystem' => isset($user) && boolval($user->hasPermission(Permission::SYSTEM)),
            'settingUrl' => admin_urls('log_management', 'browser', 'setting'),
            'settings' => [
                'browser_log_pages' => BrowserLogs::keepsPages(),
                'log_retention_browser_log' => System::log_retention_browser_log(),
            ],
            'defaultDays' => BrowserLogs::DEFAULT_DAYS,
            'retention' => LogCatalog::types()['browser_log']['retention_days'] ?? BrowserLogs::DEFAULT_DAYS,
            'userName' => function ($id) use ($users): string {
                if (is_nullorempty($id)) {
                    return '-';
                }
                $user = $users->get($id);
                return isset($user) ? trim(strval(array_get($user->value ?? [], 'user_code')) . ' ' . strval(array_get($user->value ?? [], 'user_name'))) : '#' . strval($id);
            },
        ]));
        return $content;
    }

    /**
     * The settings (system administrators): the pages opened kept or not, the days kept.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postSetting(Request $request)
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->hasPermission(Permission::SYSTEM)) {
            abort(403);
        }
        $validator = \Validator::make($request->all(), [
            'log_retention_browser_log' => 'nullable|integer|min:0|max:36500',
        ]);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }
        $days = $request->get('log_retention_browser_log');
        System::log_retention_browser_log(is_null($days) || $days === '' ? null : intval($days));
        System::browser_log_pages(boolval($request->get('browser_log_pages')));
        System::clearCache();
        admin_toastr(trans('admin.save_succeeded'));
        return redirect()->to(admin_urls('log_management', 'browser'));
    }

    /**
     * @return array{from: string|null, to: string|null, user: string|null, kind: string|null, q: string|null}
     */
    protected function filter(Request $request): array
    {
        $date = function ($value): ?string {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $value) === 1 ? $value : null;
        };
        $text = function ($value): ?string {
            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 100) : null;
        };
        $kind = $request->get('kind');
        return [
            'from' => $date($request->get('from')),
            'to' => $date($request->get('to')),
            'user' => $text($request->get('user')),
            'kind' => in_array($kind, BrowserLog::KINDS, true) ? $kind : null,
            'q' => $text($request->get('q')),
        ];
    }

    /**
     * @param array{from: string|null, to: string|null, user: string|null, kind: string|null, q: string|null} $filter
     * @return \Illuminate\Database\Eloquent\Builder<BrowserLog>
     */
    protected function query(array $filter)
    {
        $query = BrowserLog::query();
        if (isset($filter['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filter['from']));
        }
        if (isset($filter['to'])) {
            // a day: to its end; a time: to the end of its minute
            $to = Carbon::parse($filter['to']);
            $query->where('created_at', '<', strlen($filter['to']) === 10 ? $to->addDay()->startOfDay() : $to->addMinute());
        }
        if (isset($filter['kind'])) {
            $query->where('kind', $filter['kind']);
        }
        if (isset($filter['user'])) {
            $like = '%' . addcslashes($filter['user'], '%_\\') . '%';
            $ids = getModelName(SystemTableName::USER)::withTrashed()->where(function ($q) use ($like) {
                $q->where('value->user_code', 'like', $like)->orWhere('value->user_name', 'like', $like);
            })->limit(500)->pluck('id')->all();
            $query->whereIn('base_user_id', $ids);
        }
        if (isset($filter['q'])) {
            $like = '%' . addcslashes($filter['q'], '%_\\') . '%';
            $query->where(function ($q) use ($like) {
                $q->where('path', 'like', $like)->orWhere('message', 'like', $like);
            });
        }
        return $query;
    }
}
