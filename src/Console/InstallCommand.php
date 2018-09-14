<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
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

        $this->call('db:seed', ['--class' => \Exceedone\Exment\Database\Seeder\InstallSeeder::class]);
    }
}
