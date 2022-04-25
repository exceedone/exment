<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Console\InstallCommand as AdminInstallCommand;

class CheckConnectionCommand extends AdminInstallCommand
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:check-connection';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check connection database, if connect, return 1.';

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
        if (\ExmentDB::canConnection()) {
            $this->line('Connected');
            return 1;
        }
        $this->error('Not Connected');
        return 0;
    }
}
