<?php

namespace Exceedone\Exment\Console;

use Carbon\Carbon;
use Exceedone\Exment\Model\AuditReportFile;
use Exceedone\Exment\Services\AuditReport\AuditReport;
use Exceedone\Exment\Services\AuditReport\AuditReportStore;
use Illuminate\Console\Command;

/**
 * Make the IT audit report of the month or the quarter before (ISS02840), as the setting of the
 * report says (off by default): the PDF kept on the server with its SHA-256, and a notice to the
 * system administrators and the users with Audit. Scheduled every day; a period is made once
 * (a day missed is made the next day). On the page "Scheduled jobs" too.
 */
class AuditReportCommand extends Command
{
    use CommandTrait;

    protected $signature = 'exment:audit-report {--from= : the first day (Y-m-d), with --to: this period, even when the setting is off} {--to= : the last day (Y-m-d)}';

    protected $description = 'Make the IT audit report of the month or the quarter before, as its setting says';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $from = $this->option('from');
        $to = $this->option('to');
        if (!is_nullorempty($from) || !is_nullorempty($to)) {
            try {
                $period = [Carbon::createFromFormat('Y-m-d', strval($from))->startOfDay(), Carbon::createFromFormat('Y-m-d', strval($to))->startOfDay()];
            } catch (\Throwable $e) {
                $this->error('--from and --to: Y-m-d');
                return 1;
            }
        } else {
            $period = AuditReportStore::duePeriod();
            if (is_null($period)) {
                $this->info('off');
                return 0;
            }
            if (!AuditReportFile::tableExists()) {
                return 0;
            }
            $made = AuditReportFile::query()
                ->where('source', AuditReportFile::SOURCE_SCHEDULE)
                ->whereDate('period_from', $period[0]->format('Y-m-d'))
                ->whereDate('period_to', $period[1]->format('Y-m-d'))
                ->exists();
            if ($made) {
                $this->info('already made');
                return 0;
            }
        }

        $report = new AuditReport($period[0], $period[1]);
        [, $record] = AuditReportStore::make($report, AuditReportFile::SOURCE_SCHEDULE);
        if (is_null($record)) {
            return 0;
        }
        $count = AuditReportStore::notify($report, $record);
        $this->info($report->from()->format('Y-m-d') . ' - ' . $report->to()->format('Y-m-d') . ': ' . $record->sha256 . ' (' . $count . ' told)');
        return 0;
    }
}
