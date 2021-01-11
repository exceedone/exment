<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\Update\UpdateService;

class TotalUpdateCommand extends Command
{
    use CommandTrait, InstallUpdateTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:total-update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the exment package';

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
     * @return void
     */
    public function handle()
    {
        UpdateService::updateExment();
    }
}
