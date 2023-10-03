<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\RefreshDataService;

/**
 * Refresh custom data.
 */
class RefreshDataCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:refreshdata';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh custom data';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();
    }

    /**
     * Execute the console command.
     *
     * @return int|void
     */
    public function handle()
    {
        if (!$this->confirm('Really refresh data? All refresh custom data.')) {
            return;
        }

        RefreshDataService::refresh();

        return 0;
    }
}
