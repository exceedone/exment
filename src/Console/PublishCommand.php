<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;

class PublishCommand extends Command
{
    use CommandTrait, InstallUpdateTrait;

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
     * @return void
     */
    public function handle()
    {
        $this->publishStaticFiles();

        $this->createBootstrapFile();
    }
}
