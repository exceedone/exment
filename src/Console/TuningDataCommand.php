<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\SystemRequire\Tuning\DataStats;
use Illuminate\Console\Command;

/**
 * Count the data for the tuning page (ISS02927): the rows, the deleted rows and the revisions of
 * each table, the sizes, the attachments. Runs every night from the schedule; the tuning page
 * shows what was counted last. Only reads the data.
 *
 *   php artisan exment:tuning-data
 */
class TuningDataCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:tuning-data';

    /**
     * @var string
     */
    protected $description = 'Count the data for the tuning page (rows, deleted rows, revisions, sizes, attachments). Changes no data.';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $stats = DataStats::collect();
        $this->info('tuning data: ' . count($stats['tables']) . ' tables, ' . intval($stats['files']['count']) . ' files');
        return 0;
    }
}
