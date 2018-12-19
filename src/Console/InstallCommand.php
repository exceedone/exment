<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Encore\Admin\Console\InstallCommand as AdminInstallCommand;

class InstallCommand extends AdminInstallCommand
{
    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:install';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the exment package';

    /**
     * Install directory.
     *
     * @var string
     */
    protected $directory = '';

    /**
     * Execute the console command.
     *
     * @return void
     */
    public function handle()
    {
        \Exceedone\Exment\Middleware\Morph::defineMorphMap();
        \Exceedone\Exment\Middleware\Initialize::initializeConfig(false);

        $this->initDatabase();
        $this->initAdminDirectory();

        $this->call('passport:keys');
    }

    /**
     * Create tables and seed it.
     *
     * @return void
     */
    public function initDatabase()
    {
        $this->call('migrate');

        $this->call('db:seed', ['--class' => \Exceedone\Exment\Database\Seeder\InstallSeeder::class]);
    }
}
