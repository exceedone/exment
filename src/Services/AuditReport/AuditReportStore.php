<?php

namespace Exceedone\Exment\Services\AuditReport;

use Carbon\Carbon;
use Exceedone\Exment\Model\AuditReportFile;
use Exceedone\Exment\Model\NotifyNavbar;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Services\NotifyService;

/**
 * The IT audit reports made as PDF (ISS02840): the PDF itself, the record of each one made with
 * its SHA-256 (a file given to an auditor can be checked later against it), the ones the job makes
 * each month or quarter (kept on the server, 600), and the notice of those to the system
 * administrators and the users with Audit (the bell, and a mail without the file when it is set).
 */
class AuditReportStore
{
    public const SCHEDULE_OFF = 'off';
    public const SCHEDULE_MONTHLY = 'monthly';
    public const SCHEDULE_QUARTERLY = 'quarterly';
    public const SCHEDULES = [self::SCHEDULE_OFF, self::SCHEDULE_MONTHLY, self::SCHEDULE_QUARTERLY];

    /** Where the files of the job are kept, under storage/app. */
    public const ROOT = 'audit_reports';

    /** The rows of a section in the PDF. */
    public const PDF_ROWS = 2000;

    public static function schedule(): string
    {
        $value = strval(System::audit_report_schedule());
        return in_array($value, static::SCHEDULES, true) ? $value : static::SCHEDULE_OFF;
    }

    /**
     * The period the job makes now (the month or the quarter before), null when it is off.
     *
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public static function duePeriod(?Carbon $now = null): ?array
    {
        $now = $now ?? Carbon::now();
        switch (static::schedule()) {
            case static::SCHEDULE_MONTHLY:
                $from = $now->copy()->startOfMonth()->subMonthNoOverflow()->startOfDay();
                return [$from, $from->copy()->endOfMonth()->startOfDay()];
            case static::SCHEDULE_QUARTERLY:
                return AuditReport::previousQuarter($now);
        }
        return null;
    }

    /**
     * Make the PDF and record it. The job keeps the file; the page gives it and keeps its hash.
     *
     * @param mixed $user the login user who makes it (null: the job)
     * @return array{0: string, 1: AuditReportFile|null} the PDF and its record (null: not recorded)
     */
    public static function make(AuditReport $report, string $source, $user = null): array
    {
        $pdf = static::pdf($report, $user);
        $record = null;
        if (AuditReportFile::tableExists()) {
            $file = null;
            if ($source === AuditReportFile::SOURCE_SCHEDULE) {
                $file = static::keep($report, $pdf);
            }
            $record = AuditReportFile::create([
                'period_from' => $report->from()->format('Y-m-d'),
                'period_to' => $report->to()->format('Y-m-d'),
                'source' => $source,
                'sha256' => hash('sha256', $pdf),
                'size' => strlen($pdf),
                'file' => $file,
                'made_user_id' => is_object($user) ? $user->base_user_id ?? null : null,
                'created_at' => Carbon::now(),
            ]);
        }
        return [$pdf, $record];
    }

    /**
     * The made report with this SHA-256 (the check of a file given to an auditor).
     */
    public static function findByHash(string $sha256): ?AuditReportFile
    {
        if (!AuditReportFile::tableExists() || preg_match('/^[0-9a-f]{64}$/', $sha256) !== 1) {
            return null;
        }
        return AuditReportFile::query()->where('sha256', $sha256)->orderBy('id')->first();
    }

    /**
     * The file of a report of the job, null when it is not kept (any more).
     */
    public static function path(AuditReportFile $record): ?string
    {
        if (is_nullorempty($record->file) || preg_match('/^[A-Za-z0-9_.-]+\.pdf$/', strval($record->file)) !== 1) {
            return null;
        }
        $path = storage_path('app/' . static::ROOT . '/' . $record->file);
        return is_file($path) ? $path : null;
    }

    /**
     * The users told of a report of the job: the system administrators and the users with
     * Audit (base user ids).
     *
     * @return array<int, int>
     */
    public static function recipients(AuditReport $report): array
    {
        return $report->auditorIds();
    }

    /**
     * Tell the recipients that the report of a period is made: the bell, and a mail when
     * audit_report_mail is on. Neither has the file (it may hold personal data): it is on the page.
     *
     * @return int the users told
     */
    public static function notify(AuditReport $report, AuditReportFile $record): int
    {
        $subject = strval(exmtrans('audit_report.notice_subject', ['from' => $report->from()->format('Y-m-d'), 'to' => $report->to()->format('Y-m-d')]));
        $body = strval(exmtrans('audit_report.notice_body', [
            'from' => $report->from()->format('Y-m-d'),
            'to' => $report->to()->format('Y-m-d'),
            'sha256' => $record->sha256,
            'url' => admin_urls('log_management', 'audit'),
        ]));
        $mail = boolval(System::audit_report_mail());
        $count = 0;
        foreach (static::recipients($report) as $userId) {
            try {
                $navbar = new NotifyNavbar();
                $navbar->fill([
                    'notify_id' => 0,
                    'target_user_id' => $userId,
                    'notify_subject' => mb_substr($subject, 0, 200),
                    'notify_body' => mb_substr($body, 0, 2000),
                    'trigger_user_id' => null,
                ]);
                $navbar->save();
                $count++;
                if (!$mail) {
                    continue;
                }
                $user = getModelName(\Exceedone\Exment\Enums\SystemTableName::USER)::find($userId);
                if (isset($user) && !is_null(NotifyService::getAddress($user))) {
                    NotifyService::notifyMail(['to' => $user, 'subject' => $subject, 'body' => $body]);
                }
            } catch (\Throwable $e) {
                \Log::warning('Exment: the notice of the IT audit report was not sent: ' . get_class($e));
            }
        }
        return $count;
    }

    /**
     * Keep the PDF of the job (600, the folder 700).
     */
    protected static function keep(AuditReport $report, string $pdf): ?string
    {
        $dir = storage_path('app/' . static::ROOT);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            return null;
        }
        $name = 'audit_report_' . $report->from()->format('Ymd') . '-' . $report->to()->format('Ymd') . '_' . Carbon::now()->format('YmdHis') . '.pdf';
        $path = $dir . '/' . $name;
        if (@file_put_contents($path, $pdf) === false) {
            return null;
        }
        @chmod($path, 0600);
        return $name;
    }

    /**
     * The whole report as PDF.
     *
     * @param mixed $user the login user who makes it (null: the job)
     */
    public static function pdf(AuditReport $report, $user = null): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Exment');
        $pdf->SetTitle(strval(exmtrans('audit_report.header')));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont(static::font(), '', 8);
        $pdf->AddPage();

        $madeBy = is_object($user)
            ? trim(strval($user->user_code ?? '') . ' ' . strval($user->user_name ?? ''))
            : strval(exmtrans('audit_report.made_by_job'));
        $html = '<h1>' . e(exmtrans('audit_report.header')) . '</h1>'
            . '<p>' . e(exmtrans('audit_report.period')) . ': ' . e($report->from()->format('Y-m-d')) . ' - ' . e($report->to()->format('Y-m-d'))
            . '<br>' . e(exmtrans('audit_report.made_at')) . ': ' . e(Carbon::now()->format('Y-m-d H:i'))
            . '<br>' . e(exmtrans('audit_report.made_by')) . ': ' . e($madeBy)
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

    public static function loginLogNote(AuditReport $report): string
    {
        $since = $report->loginLogSince();
        return isset($since)
            ? strval(exmtrans('audit_report.login_log_since', ['date' => $since->format('Y-m-d')]))
            : strval(exmtrans('audit_report.login_log_none'));
    }

    /**
     * A font of TCPDF with the letters of the language (and the Latin ones).
     */
    protected static function font(): string
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
}
