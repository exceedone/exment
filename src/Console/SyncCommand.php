<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Sync\SyncEngine;
use Illuminate\Console\Command;

/**
 * The data sync (ISS00113): start a run of each mapping whose time has come. From the schedule
 * every 5 minutes, while the feature is used.
 *
 *   php artisan exment:sync
 */
class SyncCommand extends Command
{
    use CommandTrait;

    /**
     * @var string
     */
    protected $signature = 'exment:sync';

    /**
     * @var string
     */
    protected $description = 'Run the data sync of each mapping whose time has come.';

    public function __construct()
    {
        parent::__construct();
        $this->initExmentCommand();
    }

    public function handle(): int
    {
        $runs = SyncEngine::due();
        $this->line('Started: ' . count($runs));
        return 0;
    }
}
