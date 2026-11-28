<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Services\Insights\InsightReport;
use Exceedone\Exment\Services\Insights\Insights;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The page "Insights" (ISS02964): tiles and charts of the numbers counted every night from the
 * logs, for a period, with links to the logs they come from, and a CSV. The system
 * administrators and Audit (Auth\Permission "insights"); the names of the people only for Audit.
 */
class InsightsController extends AdminControllerBase
{
    public function __construct()
    {
        $this->setPageInfo(exmtrans('insights.header'), exmtrans('insights.header'), exmtrans('insights.description'), 'fa-line-chart');
    }

    protected static function denyUnlessAllowed(): void
    {
        if (!Permission::canAudit()) {
            abort(403);
        }
    }

    /** Whether the user sees the people (Audit), not only the totals. */
    protected static function perPerson(): bool
    {
        $user = \Exment::user();
        return isset($user) && boolval($user->hasPermission(Permission::AUDIT));
    }

    public function index(Request $request, Content $content)
    {
        static::denyUnlessAllowed();
        $this->AdminContent($content);
        if (!InsightDaily::tableExists()) {
            $content->row('<div class="alert alert-warning">' . esc_html(exmtrans('insights.no_table')) . '</div>');
            return $content;
        }
        $report = InsightReport::fromRequest($request, static::perPerson());
        $range = ['from' => $report->from->format('Y-m-d'), 'to' => $report->to->format('Y-m-d')];
        $query = function (array $more = []) use ($range) {
            return '?' . http_build_query($range + $more);
        };
        $counted = InsightDaily::query()->where('metric', 'active_day')->max('date');
        $content->row(view('exment::insights.index', [
            'report' => $report,
            'dates' => $report->dates(),
            'users' => $report->users(),
            'tables' => $report->tables(),
            'growth' => $report->growth(),
            'security' => $report->security(),
            'api' => $report->api(),
            'delivery' => $report->delivery(),
            'browser' => $report->browser(),
            'health' => $report->health(),
            'features' => $report->features(),
            // the security: from the checks and the records Exment keeps (no secret)
            'sec' => (new \Exceedone\Exment\Services\Insights\InsightSecurity($report->from, $report->to, $report->perPerson))->cards(),
            'perPerson' => $report->perPerson,
            'periods' => InsightReport::PERIODS,
            'days' => intval($request->query('days', 0)),
            'counted' => isset($counted) ? substr(strval($counted), 0, 10) : null,
            'inactiveDays' => InsightReport::INACTIVE_DAYS,
            'slowMs' => Insights::SLOW_PAGE_MS,
            'url' => admin_url('insights'),
            'csvUrl' => admin_urls('insights', 'export.csv') . $query(),
            // where each number comes from, filtered by the period
            'links' => [
                'logins' => admin_url('log_management') . $query(),
                'login_failures' => admin_url('log_management') . $query(['result' => 'failure']),
                'audit' => admin_urls('log_management', 'audit') . $query(),
                'operation_logs' => admin_urls('auth', 'logs'),
                'tuning' => admin_url('tuning'),
                'api_keys' => admin_url('api_keys'),
                'mcp' => admin_urls('log_management', 'mcp') . $query(),
                'mcp_errors' => admin_urls('log_management', 'mcp') . $query(['status' => 'error']),
                'mails' => admin_urls('data', \Exceedone\Exment\Enums\SystemTableName::MAIL_SEND_LOG),
                'app_log' => admin_url('log_management'),
                'browser' => admin_urls('log_management', 'browser') . $query(['kind' => 'error']),
                'browser_pages' => admin_urls('log_management', 'browser') . $query(['kind' => 'page']),
                'jobs' => admin_url('scheduled_jobs'),
                'feature' => admin_url('feature'),
                'plugin' => admin_url('plugin'),
                'data' => admin_url('data'),
            ],
        ]));
        return $content;
    }

    /**
     * All the numbers of the period: date, metric, key, value.
     *
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function csv(Request $request)
    {
        static::denyUnlessAllowed();
        abort_unless(InsightDaily::tableExists(), 404);
        $report = InsightReport::fromRequest($request, static::perPerson());
        $rows = $report->csvRows();
        $name = 'insights_' . $report->from->format('Ymd') . '_' . $report->to->format('Ymd') . '_' . Carbon::now()->format('His') . '.csv';
        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            // a BOM: Excel reads the UTF-8
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['date', 'metric', 'key', 'value']);
            foreach ($rows as $row) {
                fputcsv($out, array_map(function ($value) {
                    // a cell must not start a formula in a spreadsheet
                    $value = strval($value);
                    return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
                }, $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
