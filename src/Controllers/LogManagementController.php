<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\LoginLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\LogManagement\LogArchiver;
use Exceedone\Exment\Services\LogManagement\LogCatalog;
use Exceedone\Exment\Services\LogManagement\LogPrivacy;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The log management (ISS02839): the kinds of logs, how long each is kept, their sizes, the
 * settings of the application log and of the operation log, the login records (filter, CSV) and
 * the files of the application log. The system administrators and the role groups with "Audit"
 * see it (Auth\Permission, "log_management"); only the system administrators change the settings
 * and download the files of the application log (they may hold personal data).
 */
class LogManagementController extends AdminControllerBase
{
    /** The login records shown on the page; the CSV has all that the filter finds. */
    public const PAGE_ROWS = 100;

    /** The most rows of a CSV of the login records. */
    public const CSV_ROWS = 100000;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('log_management.header'), exmtrans('log_management.header'), exmtrans('log_management.description'), 'fa-archive');
    }

    protected static function denyUnlessAudit(): void
    {
        if (!Permission::canAudit()) {
            abort(403);
        }
    }

    protected static function denyUnlessSystem(): void
    {
        $user = \Exment::user();
        if (!isset($user) || !$user->hasPermission(Permission::SYSTEM)) {
            abort(403);
        }
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        static::denyUnlessAudit();
        $this->AdminContent($content);
        $user = \Exment::user();
        $isSystem = isset($user) && $user->hasPermission(Permission::SYSTEM);

        $filter = $this->loginFilter($request);
        $logins = LoginLog::tableExists() ? $this->loginQuery($filter)->orderByDesc('id')->limit(static::PAGE_ROWS)->get() : collect();

        $files = [];
        foreach (glob(storage_path('logs') . '/*.log') ?: [] as $file) {
            $files[] = ['name' => basename($file), 'size' => intval(@filesize($file)), 'modified' => intval(@filemtime($file))];
        }
        usort($files, function ($a, $b) {
            return $b['modified'] <=> $a['modified'];
        });

        $content->row(view('exment::log_management.index', [
            'types' => LogCatalog::types(),
            'isSystem' => $isSystem,
            'settingUrl' => admin_urls('log_management', 'setting'),
            'settings' => [
                'log_retention_login_log' => System::log_retention_login_log(),
                'log_retention_permission_change' => System::log_retention_permission_change(),
                'log_retention_app_log' => System::log_retention_app_log(),
                'log_app_level' => strval(System::log_app_level()),
                'log_app_daily' => boolval(System::log_app_daily()),
                'operation_log_scope' => LogSettings::operationLogScope(),
                'log_archive_enabled' => LogArchiver::enabled(),
                'log_archive_driver' => LogArchiver::driver(),
                'log_archive_years' => LogArchiver::years(),
                'log_archive_encrypt' => LogArchiver::encrypted(),
                'log_store_input' => LogPrivacy::storeInput(),
                'log_ip_mode' => LogPrivacy::ipMode(),
                'mail_log_no_body' => !LogPrivacy::mailBody(),
            ],
            'ipModes' => LogPrivacy::IP_MODES,
            'drivers' => LogArchiver::DRIVERS,
            'archives' => $isSystem ? LogArchiver::files() : [],
            'archiveUrl' => admin_urls('log_management', 'archives'),
            'levels' => LogSettings::LEVELS,
            'scopes' => LogSettings::SCOPES,
            'defaults' => ['login_log' => LogCatalog::DEFAULT_LOGIN_LOG_DAYS, 'permission_change' => LogCatalog::DEFAULT_PERMISSION_CHANGE_DAYS, 'app_log' => LogCatalog::DEFAULT_APP_LOG_DAYS],
            'operationLogUrl' => admin_url('auth/logs'),
            'filter' => $filter,
            'logins' => $logins,
            'pageRows' => static::PAGE_ROWS,
            'csvUrl' => admin_urls_query('log_management', 'logins.csv', array_filter($filter, function ($v) {
                return $v !== null && $v !== '';
            })),
            'files' => $files,
            'fileUrl' => admin_urls('log_management', 'files'),
            'bytes' => function ($bytes): string {
                return static::bytes($bytes);
            },
        ]));
        return $content;
    }

    /**
     * Save the settings (system administrators).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postSetting(Request $request)
    {
        static::denyUnlessSystem();
        $validator = \Validator::make($request->all(), [
            'log_retention_login_log' => 'nullable|integer|min:0|max:36500',
            'log_retention_permission_change' => 'nullable|integer|min:0|max:36500',
            'log_retention_app_log' => 'nullable|integer|min:0|max:36500',
            'log_app_level' => 'nullable|in:' . implode(',', LogSettings::LEVELS),
            'operation_log_scope' => 'required|in:' . implode(',', LogSettings::SCOPES),
            'log_archive_driver' => 'nullable|in:' . implode(',', LogArchiver::DRIVERS),
            'log_archive_years' => 'nullable|integer|min:0|max:100',
            'log_ip_mode' => 'nullable|in:' . implode(',', LogPrivacy::IP_MODES),
        ]);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }
        foreach (['log_retention_login_log', 'log_retention_permission_change', 'log_retention_app_log'] as $key) {
            $value = $request->get($key);
            System::{$key}(is_null($value) || $value === '' ? null : intval($value));
        }
        System::log_app_level(is_nullorempty($request->get('log_app_level')) ? null : strval($request->get('log_app_level')));
        System::log_app_daily(boolval($request->get('log_app_daily')));
        System::operation_log_scope(strval($request->get('operation_log_scope')));
        System::log_archive_enabled(boolval($request->get('log_archive_enabled')));
        System::log_archive_driver(strval($request->get('log_archive_driver') ?: LogArchiver::DRIVER_LOCAL));
        $years = $request->get('log_archive_years');
        System::log_archive_years(is_null($years) || $years === '' || intval($years) === 0 ? null : intval($years));
        System::log_archive_encrypt(boolval($request->get('log_archive_encrypt')));
        System::log_store_input(boolval($request->get('log_store_input')));
        System::log_ip_mode(strval($request->get('log_ip_mode') ?: LogPrivacy::IP_KEEP));
        System::mail_log_no_body(boolval($request->get('mail_log_no_body')));
        System::clearCache();

        admin_toastr(trans('admin.save_succeeded'));
        return redirect(admin_url('log_management'));
    }

    /**
     * The login records the filter finds, as CSV.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function loginsCsv(Request $request)
    {
        static::denyUnlessAudit();
        $filter = $this->loginFilter($request);
        $query = $this->loginQuery($filter);
        $name = 'login_logs_' . Carbon::now()->format('YmdHis') . '.csv';

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // a BOM: Excel reads the UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['created_at', 'result', 'failure_reason', 'login_name', 'base_user_id', 'provider', 'provider_name', 'ip', 'user_agent']);
            $count = 0;
            $query->orderBy('id')->chunk(1000, function ($rows) use ($out, &$count) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(function ($value) {
                        // a cell must not start a formula in a spreadsheet
                        $value = strval($value);
                        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
                    }, [
                        optional($row->created_at)->format('Y-m-d H:i:s'),
                        $row->result,
                        $row->failure_reason,
                        $row->login_name,
                        $row->base_user_id,
                        $row->provider,
                        $row->provider_name,
                        $row->ip,
                        $row->user_agent,
                    ]));
                    if (++$count >= static::CSV_ROWS) {
                        return false;
                    }
                }
                return true;
            });
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * A file of the application log (system administrators).
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function file(Request $request, string $name)
    {
        static::denyUnlessSystem();
        // a file of storage/logs, by its name only
        if (preg_match('/^[A-Za-z0-9._-]+\.log$/', $name) !== 1) {
            abort(404);
        }
        $path = storage_path('logs/' . $name);
        if (!is_file($path)) {
            abort(404);
        }
        return response()->download($path, $name, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }

    /**
     * An archive file (system administrators): it may hold personal data.
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function archive(Request $request, string $kind, string $name)
    {
        static::denyUnlessSystem();
        $path = LogArchiver::ROOT . '/' . $kind . '/' . $name;
        if (!LogArchiver::isArchive($path)) {
            abort(404);
        }
        $content = LogArchiver::read($path);
        if (is_null($content)) {
            abort(404);
        }
        return response($content, 200, [
            'Content-Type' => 'application/gzip',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @return array{from: string|null, to: string|null, name: string|null, result: string|null}
     */
    protected function loginFilter(Request $request): array
    {
        $date = function ($value): ?string {
            return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 ? $value : null;
        };
        $result = $request->get('result');
        $name = $request->get('name');
        return [
            'from' => $date($request->get('from')),
            'to' => $date($request->get('to')),
            'name' => is_string($name) && trim($name) !== '' ? mb_substr(trim($name), 0, 100) : null,
            'result' => in_array($result, [LoginLog::RESULT_SUCCESS, LoginLog::RESULT_FAILURE, LoginLog::RESULT_LOCKOUT, LoginLog::RESULT_LOGOUT], true) ? $result : null,
        ];
    }

    /**
     * @param array{from: string|null, to: string|null, name: string|null, result: string|null} $filter
     * @return \Illuminate\Database\Eloquent\Builder<LoginLog>
     */
    protected function loginQuery(array $filter)
    {
        $query = LoginLog::query();
        if (isset($filter['from'])) {
            $query->where('created_at', '>=', Carbon::parse($filter['from'])->startOfDay());
        }
        if (isset($filter['to'])) {
            $query->where('created_at', '<', Carbon::parse($filter['to'])->addDay()->startOfDay());
        }
        if (isset($filter['name'])) {
            $query->where('login_name', 'like', '%' . addcslashes($filter['name'], '%_\\') . '%');
        }
        if (isset($filter['result'])) {
            $query->where('result', $filter['result']);
        }
        return $query;
    }

    /**
     * @param mixed $bytes
     */
    public static function bytes($bytes): string
    {
        if (!is_numeric($bytes)) {
            return '-';
        }
        $bytes = floatval($bytes);
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($unit === 'B' ? intval($bytes) : round($bytes, 1)) . ' ' . $unit;
            }
            $bytes /= 1024;
        }
        return strval($bytes);
    }
}
