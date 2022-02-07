<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;

class VersionCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:version';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Exment version';

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
     * @return int
     */
    public function handle()
    {
        $this->line(\Exment::getExmentCurrentVersion());
        return 0;
    }
}
