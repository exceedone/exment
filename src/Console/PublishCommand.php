<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    use CommandTrait;
    use InstallUpdateTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:publish';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish the exment files';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

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
        $this->publishStaticFiles();

        $this->createExmentBootstrapFile();

        return 0;
    }
}
