<?php

namespace Exceedone\Exment\Console;

use Encore\Admin\Console\InstallCommand as AdminInstallCommand;

class InstallCommand extends AdminInstallCommand
{
    use CommandTrait;
    use InstallUpdateTrait;

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

        $this->initDatabase();

        $this->initAdminDirectory();

        //$this->call('passport:keys');
        return 0;
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
