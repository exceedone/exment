<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Enums\SystemTableName;
use Exceedone\Exment\Model\AuditReportFile;
use Exceedone\Exment\Model\CustomTable;
use Exceedone\Exment\Model\DataAccessLog;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\AuditReport\AuditReportStore;
use Exceedone\Exment\Services\LogManagement\LogPrivacy;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use ExmentAdminCore\Admin\Auth\Database\OperationLog as OperationLogModel;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * The IT audit report (ISS02840), from the page of the log management: the accounts, the
 * administrators, the role groups, the logins, the API keys and what is given outside, for a
 * period; on the page, as CSV (a section) and as PDF (all, its SHA-256 recorded). The reports the
 * job makes each month or quarter, and the check of a PDF against the reports made. The system
 * administrators and the role groups with "Audit"; the settings, the system administrators. A CSV
 * or a PDF given is kept in the operation log.
 */
class AuditReportController extends AdminControllerBase
{
    /** The rows of a section on the page; the CSV has all of them. */
    public const PAGE_ROWS = 500;

    /** The made reports listed on the page. */
    public const FILE_ROWS = 50;

    public function __construct()
    {
        $this->setPageInfo(exmtrans('audit_report.header'), exmtrans('audit_report.header'), exmtrans('audit_report.description'), 'fa-clipboard');
    }

    protected static function denyUnlessAudit(): void
    {
        if (!Permission::canAudit()) {
            abort(403);
        }
    }

    protected static function isSystem(): bool
    {
        $user = \Exment::user();
        return isset($user) && boolval($user->hasPermission(Permission::SYSTEM));
    }

    /**
     * @return Content
     */
    public function index(Request $request, Content $content)
    {
        static::denyUnlessAudit();
        $this->AdminContent($content);
        $report = $this->report($request);
        $query = $this->query($report);

        $content->row(view('exment::log_management.audit', [
            'report' => $report,
            'sections' => $report->sections(),
            'loginLogNote' => AuditReportStore::loginLogNote($report),
            'ipMode' => LogPrivacy::ipMode(),
            'pageRows' => static::PAGE_ROWS,
            'isSystem' => static::isSystem(),
            'schedule' => AuditReportStore::schedule(),
            'schedules' => AuditReportStore::SCHEDULES,
            'mail' => boolval(System::audit_report_mail()),
            'files' => AuditReportFile::tableExists() ? AuditReportFile::query()->orderByDesc('id')->limit(static::FILE_ROWS)->get() : collect(),
            'url' => admin_urls('log_management', 'audit'),
            'logUrl' => admin_url('log_management'),
            'fileUrl' => admin_urls('log_management', 'audit', 'files'),
            'settingUrl' => admin_urls('log_management', 'audit', 'setting'),
            'verifyUrl' => admin_urls('log_management', 'audit', 'verify'),
            'pdfUrl' => admin_urls_query('log_management', 'audit.pdf', $query),
            'dataTable' => $query['data_table'] ?? '',
            'dataUser' => $query['data_user'] ?? '',
            'csvUrl' => function (string $section) use ($query): string {
                return admin_urls_query('log_management', 'audit', $section . '.csv', $query);
            },
            'userName' => function ($userId): string {
                if (is_nullorempty($userId)) {
                    return strval(exmtrans('audit_report.made_by_job'));
                }
                $user = getModelName(\Exceedone\Exment\Enums\SystemTableName::USER)::withTrashed()->find($userId);
                return isset($user) ? trim(strval(array_get($user->value ?? [], 'user_code')) . ' ' . strval(array_get($user->value ?? [], 'user_name'))) : '#' . strval($userId);
            },
        ]));
        return $content;
    }

    /**
     * A section as CSV.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function csv(Request $request, string $section)
    {
        static::denyUnlessAudit();
        if (!in_array($section, AuditReport::sectionKeys(), true)) {
            abort(404);
        }
        $report = $this->report($request);
        $data = $report->section($section);
        $this->logExport($request);
        $name = 'audit_' . $section . '_' . $report->from()->format('Ymd') . '-' . $report->to()->format('Ymd') . '.csv';

        return response()->streamDownload(function () use ($data) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // a BOM: Excel reads the UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, array_values($data['columns']));
            foreach ($data['rows'] as $row) {
                fputcsv($out, array_map([static::class, 'csvCell'], $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * The whole report as PDF; its SHA-256 is recorded (and given in a header).
     *
     * @return \Illuminate\Http\Response
     */
    public function pdf(Request $request)
    {
        static::denyUnlessAudit();
        $report = $this->report($request);
        $this->logExport($request);
        [$body] = AuditReportStore::make($report, AuditReportFile::SOURCE_PAGE, \Exment::user());
        $name = 'audit_report_' . $report->from()->format('Ymd') . '-' . $report->to()->format('Ymd') . '.pdf';

        return response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
            'X-Content-SHA256' => hash('sha256', $body),
        ]);
    }

    /**
     * A report the job made, kept on the server.
     *
     * @return \Symfony\Component\HttpFoundation\BinaryFileResponse
     */
    public function file(Request $request, string $id)
    {
        static::denyUnlessAudit();
        $record = AuditReportFile::tableExists() ? AuditReportFile::query()->find($id) : null;
        $path = $record instanceof AuditReportFile ? AuditReportStore::path($record) : null;
        if (is_null($path)) {
            abort(404);
        }
        $this->logExport($request);
        return response()->download($path, basename($path), ['Content-Type' => 'application/pdf', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * Check a PDF against the reports made: the same SHA-256, the file was not changed.
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function verify(Request $request)
    {
        static::denyUnlessAudit();
        $file = $request->file('report');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            admin_toastr(exmtrans('audit_report.verify_none'), 'error');
            return redirect()->to(admin_urls('log_management', 'audit'));
        }
        $record = AuditReportStore::findByHash(strval(hash_file('sha256', strval($file->getRealPath()))));
        if (isset($record)) {
            admin_toastr(exmtrans('audit_report.verify_ok', [
                'from' => $record->period_from->format('Y-m-d'),
                'to' => $record->period_to->format('Y-m-d'),
                'made_at' => optional($record->created_at)->format('Y-m-d H:i'),
            ]));
        } else {
            admin_toastr(exmtrans('audit_report.verify_ng'), 'error');
        }
        return redirect()->to(admin_urls('log_management', 'audit'));
    }

    /**
     * The settings of the reports the job makes (system administrators).
     *
     * @return \Illuminate\Http\RedirectResponse
     */
    public function postSetting(Request $request)
    {
        if (!static::isSystem()) {
            abort(403);
        }
        $validator = \Validator::make($request->all(), [
            'audit_report_schedule' => 'required|in:' . implode(',', AuditReportStore::SCHEDULES),
        ]);
        if ($validator->fails()) {
            return back()->withInput()->withErrors($validator);
        }
        System::audit_report_schedule(strval($request->get('audit_report_schedule')));
        System::audit_report_mail(boolval($request->get('audit_report_mail')));
        System::clearCache();
        admin_toastr(trans('admin.save_succeeded'));
        return redirect()->to(admin_urls('log_management', 'audit'));
    }

    /**
     * Who read and who changed a record (ISS02905), the newest first.
     *
     * @return Content
     */
    public function record(Request $request, Content $content)
    {
        static::denyUnlessAudit();
        $table = CustomTable::getEloquent(strval($request->get('table')));
        $id = $request->get('id');
        if (!isset($table) || !is_numeric($id)) {
            abort(404);
        }
        $this->AdminContent($content);
        $page = max(1, intval($request->get('page', 1)));
        $rows = static::recordAccess($table, intval($id), static::RECORD_ROWS + 1, ($page - 1) * static::RECORD_ROWS);
        $more = count($rows) > static::RECORD_ROWS;
        $rows = array_slice($rows, 0, static::RECORD_ROWS);
        $query = ['table' => $table->table_name, 'id' => intval($id)];
        $content->row(view('exment::log_management.record_access', [
            'rows' => $rows,
            'title' => strval($table->table_view_name) . ' #' . intval($id),
            'recordUrl' => admin_urls('data', $table->table_name, intval($id)),
            'prevUrl' => $page > 1 ? admin_urls_query('log_management', 'audit', 'record', $query + ['page' => $page - 1]) : null,
            'nextUrl' => $more ? admin_urls_query('log_management', 'audit', 'record', $query + ['page' => $page + 1]) : null,
            'moreUrl' => null,
            'readsKept' => \Exceedone\Exment\Services\LogManagement\LogSettings::logsMethod('GET'),
        ]));
        return $content;
    }

    /** The rows of the page of a record. */
    public const RECORD_ROWS = 50;

    /** The rows of the box on the detail page. */
    public const RECORD_BOX_ROWS = 20;

    /**
     * The box "who read and who changed it" of the detail page of a record, null for a user without
     * Audit (ISS02905).
     */
    public static function recordAccessBox(CustomTable $table, int $id): ?string
    {
        if (!Permission::canAudit() || !DataAccessLog::tableExists()) {
            return null;
        }
        $rows = static::recordAccess($table, $id, static::RECORD_BOX_ROWS + 1, 0);
        return view('exment::log_management.record_access', [
            'rows' => array_slice($rows, 0, static::RECORD_BOX_ROWS),
            'title' => null,
            'recordUrl' => null,
            'prevUrl' => null,
            'nextUrl' => null,
            'moreUrl' => count($rows) > static::RECORD_BOX_ROWS ? admin_urls_query('log_management', 'audit', 'record', ['table' => $table->table_name, 'id' => $id, 'page' => 1]) : null,
            'readsKept' => \Exceedone\Exment\Services\LogManagement\LogSettings::logsMethod('GET'),
        ])->render();
    }

    /**
     * @return array<int, array{at: string, kind: string, via: string, user: string}>
     */
    protected static function recordAccess(CustomTable $table, int $id, int $limit, int $offset): array
    {
        if (!DataAccessLog::tableExists()) {
            return [];
        }
        $rows = DataAccessLog::query()->where('custom_table_id', $table->id)->where('record_id', $id)
            ->orderByDesc('created_at')->orderByDesc('id')->offset($offset)->limit($limit)->get();
        $users = getModelName(SystemTableName::USER)::withTrashed()->whereIn('id', $rows->pluck('base_user_id')->filter()->unique()->all())->get()->keyBy('id');
        return $rows->map(function (DataAccessLog $row) use ($users) {
            $user = is_null($row->base_user_id) ? null : $users->get($row->base_user_id);
            $kind = strval(exmtrans('audit_report.data_kind.' . $row->kind));
            // a reveal says which column was shown (ISS00148)
            if ($row->kind === DataAccessLog::KIND_REVEAL && isset($row->custom_column_id)) {
                $column = \Exceedone\Exment\Model\CustomColumn::getEloquent($row->custom_column_id);
                $kind .= isset($column) ? '（' . $column->column_view_name . '）' : '';
            }
            return [
                'at' => optional($row->created_at)->format('Y-m-d H:i:s') ?? '',
                'kind' => $kind,
                'via' => strval(exmtrans('audit_report.via.' . $row->via)),
                'user' => is_null($row->base_user_id) ? strval(exmtrans('audit_report.made_by_job'))
                    : (isset($user) ? trim(strval(array_get($user->value ?? [], 'user_code')) . ' ' . strval(array_get($user->value ?? [], 'user_name'))) : '#' . $row->base_user_id),
            ];
        })->all();
    }

    /**
     * A cell must not start a formula in a spreadsheet.
     */
    public static function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    protected function report(Request $request): AuditReport
    {
        $date = function ($value): ?Carbon {
            if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            try {
                return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable $e) {
                return null;
            }
        };
        [$defaultFrom, $defaultTo] = AuditReport::previousQuarter();
        $from = $date($request->get('from')) ?? $defaultFrom;
        $to = $date($request->get('to')) ?? $defaultTo;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        $days = $request->get('inactive_days');
        $days = is_numeric($days) ? min(3650, max(1, intval($days))) : AuditReport::DEFAULT_INACTIVE_DAYS;
        $report = new AuditReport($from, $to, $days);

        // the sections of the data: a table (its name), a user (the code) (ISS02905)
        $tableName = $request->get('data_table');
        $table = is_string($tableName) && $tableName !== '' ? CustomTable::getEloquent($tableName) : null;
        $userCode = $request->get('data_user');
        $user = is_string($userCode) && trim($userCode) !== ''
            ? getModelName(SystemTableName::USER)::withTrashed()->where('value->user_code', trim($userCode))->first()
            : null;
        return $report->filterData(isset($table) ? intval($table->id) : null, isset($user) ? intval($user->id) : null);
    }

    /**
     * @return array<string, string>
     */
    protected function query(AuditReport $report): array
    {
        $query = [
            'from' => $report->from()->format('Y-m-d'),
            'to' => $report->to()->format('Y-m-d'),
            'inactive_days' => strval($report->inactiveDays()),
        ];
        $filter = $report->dataFilter();
        if (isset($filter['table'])) {
            $table = CustomTable::getEloquent($filter['table']);
            if (isset($table)) {
                $query['data_table'] = strval($table->table_name);
            }
        }
        if (isset($filter['user'])) {
            $user = getModelName(SystemTableName::USER)::withTrashed()->find($filter['user']);
            if (isset($user)) {
                $query['data_user'] = strval(array_get($user->value ?? [], 'user_code'));
            }
        }
        return $query;
    }

    /**
     * Keep a CSV or a PDF given in the operation log, also when it keeps only the changes.
     */
    protected function logExport(Request $request): void
    {
        if (LogSettings::logsMethod('GET')) {
            // the middleware keeps it
            return;
        }
        $user = \Exment::user();
        try {
            OperationLogModel::create([
                'user_id' => isset($user) ? $user->id : 0,
                'path' => substr($request->path(), 0, 255),
                'method' => $request->method(),
                'ip' => LogPrivacy::ip($request->getClientIp()),
                'input' => json_encode($request->only(['from', 'to', 'inactive_days'])),
            ]);
        } catch (\Exception $e) {
            // a record that cannot be written does not stop the report
        }
    }
}
