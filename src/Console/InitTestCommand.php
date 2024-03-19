<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Database\Seeder;

class InitTestCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:inittest {--yes}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Initialize environment for test.';

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
     * @return int|void
     */
    public function handle()
    {
        if (!boolval($this->option('yes')) && !$this->confirm('Really initialize environment? All reset this environment.')) {
            return;
        }

        $this->call('cache:clear');

        $this->call('migrate:reset');

        System::clearCache();
        $this->call('exment:install');

        System::clearCache();

        $this->call('db:seed', ['--class' => Seeder\TestDataSeeder::class]);
        $this->call('db:seed', ['--class' => Seeder\WorkflowTestDataSeeder::class]);
        return 0;
    }
}
