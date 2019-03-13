<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;

class UpdateCommand extends Command
{
    use CommandTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:update';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Update the exment package';

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
        $this->initDatabase();
    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function initDatabase()
    {
        $this->call('migrate');
    }

    /**
     * Publish static files
     *
     * @return void
     */
    public function publishStaticFiles()
    {
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'public', '--force']);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'lang', '--force']);
        $this->call('vendor:publish', ['--provider' => \Exceedone\Exment\ExmentServiceProvider::class, '--tag' => 'views_vendor', '--force']);
    }
}
