<?php

namespace Exceedone\Exment\Console;

use ExmentAdminCore\Admin\Console\InstallCommand as AdminInstallCommand;

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

        $exitCode = $this->initDatabase();
        if ($exitCode !== 0) {
            return $exitCode;
        }

        $this->initAdminDirectory();

        //$this->call('passport:keys');
        return 0;
    }

    /**
     * Create tables and seed it. --force: the install is asked for (the command, the
     * install screen), also on a site whose APP_ENV is production, where migrate and db:seed
     * would otherwise ask, and stop without a terminal ("Command cancelled") with no table made.
     *
     * @return int the exit code: 0, or that of the step that failed
     */
    public function initDatabase()
    {
        $exitCode = intval($this->call('migrate', ['--force' => true]));
        if ($exitCode !== 0) {
            $this->error('exment:install: migrate failed (exit ' . $exitCode . '). No table was made.');
            return $exitCode;
        }

        $exitCode = intval($this->call('db:seed', ['--class' => \Exceedone\Exment\Database\Seeder\InstallSeeder::class, '--force' => true]));
        if ($exitCode !== 0) {
            $this->error('exment:install: db:seed failed (exit ' . $exitCode . ').');
            return $exitCode;
        }
        return 0;
    }
}
