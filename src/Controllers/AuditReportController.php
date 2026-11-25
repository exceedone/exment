<?php

namespace Exceedone\Exment\Controllers;

use Carbon\Carbon;
use Exceedone\Exment\Enums\Permission;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\LogManagement\LogPrivacy;
use Exceedone\Exment\Services\LogManagement\LogSettings;
use ExmentAdminCore\Admin\Auth\Database\OperationLog as OperationLogModel;
use ExmentAdminCore\Admin\Layout\Content;
use Illuminate\Http\Request;

/**
 * The IT audit report (ISS02840), from the page of the log management: the accounts, the
 * administrators, the logins, the API keys and what is given outside, for a period; on the page,
 * as CSV (a section) and as PDF (all). The system administrators and the role groups with "Audit".
 * A CSV or a PDF made is kept in the operation log.
 */
class AuditReportController extends AdminControllerBase
{
    /** The rows of a section on the page; the CSV has all of them. */
    public const PAGE_ROWS = 500;

    /** The rows of a section in the PDF. */
    public const PDF_ROWS = 2000;

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
            'since' => $report->loginLogSince(),
            'ipMode' => LogPrivacy::ipMode(),
            'pageRows' => static::PAGE_ROWS,
            'url' => admin_urls('log_management', 'audit'),
            'logUrl' => admin_url('log_management'),
            'pdfUrl' => admin_urls_query('log_management', 'audit.pdf', $query),
            'csvUrl' => function (string $section) use ($query): string {
                return admin_urls_query('log_management', 'audit', $section . '.csv', $query);
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
        if (!in_array($section, AuditReport::SECTIONS, true)) {
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
     * The whole report as PDF.
     *
     * @return \Illuminate\Http\Response
     */
    public function pdf(Request $request)
    {
        static::denyUnlessAudit();
        $report = $this->report($request);
        $this->logExport($request);
        $body = $this->makePdf($report);
        $name = 'audit_report_' . $report->from()->format('Ymd') . '-' . $report->to()->format('Ymd') . '.pdf';

        return response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * A cell must not start a formula in a spreadsheet.
     */
    public static function csvCell(string $value): string
    {
        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'" . $value : $value;
    }

    protected function makePdf(AuditReport $report): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Exment');
        $pdf->SetTitle(strval(exmtrans('audit_report.header')));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont(static::pdfFont(), '', 8);
        $pdf->AddPage();

        $user = \Exment::user();
        $html = '<h1>' . e(exmtrans('audit_report.header')) . '</h1>'
            . '<p>' . e(exmtrans('audit_report.period')) . ': ' . e($report->from()->format('Y-m-d')) . ' - ' . e($report->to()->format('Y-m-d'))
            . '<br>' . e(exmtrans('audit_report.made_at')) . ': ' . e(Carbon::now()->format('Y-m-d H:i'))
            . (isset($user) ? '<br>' . e(exmtrans('audit_report.made_by')) . ': ' . e(strval($user->user_code)) . ' ' . e(strval($user->user_name)) : '')
            . '<br>' . e(static::loginLogNote($report))
            . '</p>';
        $pdf->writeHTML($html);

        foreach ($report->sections() as $section) {
            $html = '<h2>' . e($section['title']) . '</h2>';
            if (isset($section['note'])) {
                $html .= '<p>' . e($section['note']) . '</p>';
            }
            if (count($section['rows']) === 0) {
                $html .= '<p>' . e(exmtrans('audit_report.none')) . '</p>';
                $pdf->writeHTML($html);
                continue;
            }
            $html .= '<table border="0.3" cellpadding="2"><thead><tr style="background-color:#eeeeee;">';
            foreach ($section['columns'] as $label) {
                $html .= '<th>' . e($label) . '</th>';
            }
            $html .= '</tr></thead><tbody>';
            foreach (array_slice($section['rows'], 0, static::PDF_ROWS) as $row) {
                $html .= '<tr nobr="true">';
                foreach ($row as $cell) {
                    $html .= '<td>' . e($cell) . '</td>';
                }
                $html .= '</tr>';
            }
            $html .= '</tbody></table>';
            if (count($section['rows']) > static::PDF_ROWS) {
                $html .= '<p>' . e(exmtrans('audit_report.more_in_csv', ['count' => static::PDF_ROWS, 'total' => count($section['rows'])])) . '</p>';
            }
            $pdf->writeHTML($html);
        }
        return strval($pdf->Output('', 'S'));
    }

    /**
     * A font of TCPDF with the letters of the language (and the Latin ones).
     */
    protected static function pdfFont(): string
    {
        switch (\App::getLocale()) {
            case 'vi':
                return 'dejavusans';
            case 'ko':
                return 'cid0kr';
            case 'zh_CN':
                return 'cid0cs';
        }
        return 'cid0jp';
    }

    public static function loginLogNote(AuditReport $report): string
    {
        $since = $report->loginLogSince();
        return isset($since)
            ? strval(exmtrans('audit_report.login_log_since', ['date' => $since->format('Y-m-d')]))
            : strval(exmtrans('audit_report.login_log_none'));
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
        return new AuditReport($from, $to, $days);
    }

    /**
     * @return array<string, string>
     */
    protected function query(AuditReport $report): array
    {
        return [
            'from' => $report->from()->format('Y-m-d'),
            'to' => $report->to()->format('Y-m-d'),
            'inactive_days' => strval($report->inactiveDays()),
        ];
    }

    /**
     * Keep a CSV or a PDF made in the operation log, also when it keeps only the changes.
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
