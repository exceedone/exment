<?php

namespace Exceedone\Exment\Console;

use Carbon\Carbon;
use Exceedone\Exment\Model\InsightDaily;
use Exceedone\Exment\Services\Insights\Insights;
use Illuminate\Console\Command;

/**
 * Count the numbers of the page "Insights" from the logs (ISS02964): the days not counted yet,
 * up to yesterday, into insight_daily. Runs every night while the feature is used; with --from
 * and --to, those days are counted again (even when the feature is off).
 *
 *   php artisan exment:insights
 *   php artisan exment:insights --from=2026-11-01 --to=2026-11-30
 */
class InsightsCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:insights {--from= : the first day (Y-m-d)} {--to= : the last day (Y-m-d)}';

    /**
     * @var string
     */
    protected $description = 'Count the numbers of the page "Insights" from the logs (up to yesterday). Changes no data.';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        if (!InsightDaily::tableExists()) {
            $this->error('insights: the table insight_daily is not there (run php artisan migrate)');
            return 1;
        }
        $from = $this->day($this->option('from'));
        $to = $this->day($this->option('to'));
        if ((is_string($this->option('from')) && !isset($from)) || (is_string($this->option('to')) && !isset($to))) {
            $this->error('insights: a day is Y-m-d');
            return 1;
        }
        if (!isset($from) && !Insights::counted()) {
            $this->info('insights: not used (Feature management)');
            return 0;
        }
        $days = Insights::collectUntilYesterday($from, $to);
        $this->info('insights: ' . count($days) . ' days' . (count($days) > 0 ? ' (' . reset($days) . ' - ' . end($days) . ')' : ''));
        return 0;
    }

    protected function day(mixed $value): ?Carbon
    {
        if (!is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) !== 1) {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $value)->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }
}
