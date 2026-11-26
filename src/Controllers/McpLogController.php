<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\McpLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\McpLogs;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The log of the MCP calls (ISS02915) in the log management: found by the user, the time, the
 * tool and the result (the system administrators and the role groups with "Audit"; the days kept,
 * the system administrators).
 */
class McpLogController extends AdminControllerBase
{
    public const PAGE_ROWS = 100;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('mcp_log.header'), exmtrans('mcp_log.header'), exmtrans('mcp_log.description'), 'fa-plug');
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
        if (McpLog::tableExists()) {
            $rows = $this->query($filter)->orderByDesc('id')->offset(($page - 1) * static::PAGE_ROWS)->limit(static::PAGE_ROWS + 1)->get();
            $more = $rows->count() > static::PAGE_ROWS;
            $rows = $rows->take(static::PAGE_ROWS);
        }
        $user = \Exment::user();
        $users = getModelName(SystemTableName::USER)::withTrashed()->whereIn('id', $rows->pluck('base_user_id')->filter()->unique()->all())->get()->keyBy('id');
        $query = array_filter($filter, function ($v) {
            return $v !== null && $v !== '';
        });

        $content->row(view('exment::log_management.mcp', [
            'rows' => $rows,
            'filter' => $filter,
            'statuses' => McpLog::STATUSES,
            'tools' => \Exceedone\Exment\Services\Mcp\McpTools::names(),
            'page' => $page,
            'prevUrl' => $page > 1 ? admin_urls_query('log_management', 'mcp', $query + ['page' => $page - 1]) : null,
            'nextUrl' => $more ? admin_urls_query('log_management', 'mcp', $query + ['page' => $page + 1]) : null,
            'url' => admin_urls('log_management', 'mcp'),
            'logUrl' => admin_url('log_management'),
            'featureUrl' => admin_url('feature'),
            'active' => McpLogs::active(),
            'isSystem' => isset($user) && boolval($user->hasPermission(Permission::SYSTEM)),
            'settingUrl' => admin_urls('log_management', 'mcp', 'setting'),
            'retentionSetting' => System::log_retention_mcp_log(),
            'defaultDays' => McpLogs::DEFAULT_DAYS,
            'retention' => LogCatalog::types()['mcp_log']['retention_days'] ?? McpLogs::DEFAULT_DAYS,
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
     * The days kept (system administrators).
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
            'log_retention_mcp_log' => 'nullable|integer|min:0|max:36500',
        ]);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }
        $days = $request->get('log_retention_mcp_log');
        System::log_retention_mcp_log(is_null($days) || $days === '' ? null : intval($days));
        System::clearCache();
        admin_toastr(trans('admin.save_succeeded'));
        return redirect()->to(admin_urls('log_management', 'mcp'));
    }

    /**
     * @return array{from: string|null, to: string|null, user: string|null, tool: string|null, status: string|null}
     */
    protected function filter(Request $request): array
    {
        $date = function ($value): ?string {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2})?$/', $value) === 1 ? $value : null;
        };
        $text = function ($value): ?string {
            return is_string($value) && trim($value) !== '' ? mb_substr(trim($value), 0, 100) : null;
        };
        $tool = $request->get('tool');
        $status = $request->get('status');
        return [
            'from' => $date($request->get('from')),
            'to' => $date($request->get('to')),
            'user' => $text($request->get('user')),
            'tool' => is_string($tool) && in_array($tool, \Exceedone\Exment\Services\Mcp\McpTools::names(), true) ? $tool : null,
            'status' => in_array($status, McpLog::STATUSES, true) ? $status : null,
        ];
    }

    /**
     * @param array{from: string|null, to: string|null, user: string|null, tool: string|null, status: string|null} $filter
     * @return \Illuminate\Database\Eloquent\Builder<McpLog>
     */
    protected function query(array $filter)
    {
        $query = McpLog::query();
        if (isset($filter['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filter['from']));
        }
        if (isset($filter['to'])) {
            // a day: to its end; a time: to the end of its minute
            $to = Carbon::parse($filter['to']);
            $query->where('created_at', '<', strlen($filter['to']) === 10 ? $to->addDay()->startOfDay() : $to->addMinute());
        }
        if (isset($filter['tool'])) {
            $query->where('tool', $filter['tool']);
        }
        if (isset($filter['status'])) {
            $query->where('status', $filter['status']);
        }
        if (isset($filter['user'])) {
            $like = '%' . addcslashes($filter['user'], '%_\\') . '%';
            $ids = getModelName(SystemTableName::USER)::withTrashed()->where(function ($q) use ($like) {
                $q->where('value->user_code', 'like', $like)->orWhere('value->user_name', 'like', $like);
            })->limit(500)->pluck('id')->all();
            $query->whereIn('base_user_id', $ids);
        }
        return $query;
    }
}
