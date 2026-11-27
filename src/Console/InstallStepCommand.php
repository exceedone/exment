<?php

namespace Exceedone\Exment\Console;

use Exceedone\Exment\Services\Installer\InstallRunner;

/**
 * One part of "exment:install", for the web installer, which runs the install in steps
 * (InstallRunner): the files to publish, the first data, the end. The migrations are run
 * by InstallRunner itself, a few at a time. "exment:install" is not changed.
 */
class InstallStepCommand extends InstallCommand
{
    /**
     * @var string
     */
    protected $signature = 'exment:install-step {step : publish, seed or finish}';

    /**
     * @var string
     */
    protected $description = 'One step of the install of the web installer';

    /**
     * @var bool
     */
    protected $hidden = true;

    /**
     * @return int
     */
    public function handle()
    {
        switch ($this->argument('step')) {
            case InstallRunner::PHASE_PUBLISH:
                $this->publishStaticFiles();
                $this->createExmentBootstrapFile();
                return 0;

            case InstallRunner::PHASE_SEED:
                // as exment:install, after its migrations
                $exitCode = intval($this->call('db:seed', ['--class' => \Exceedone\Exment\Database\Seeder\InstallSeeder::class, '--force' => true]));
                if ($exitCode !== 0) {
                    $this->error('exment:install: db:seed failed (exit ' . $exitCode . ').');
                }
                return $exitCode;

            case InstallRunner::PHASE_FINISH:
                $this->initAdminDirectory();
                if (!\Schema::hasTable(\Exceedone\Exment\Enums\SystemTableName::SYSTEM)) {
                    $this->error('exment:install: the tables are not there.');
                    return 1;
                }
                \Exceedone\Exment\Services\Installer\InstalledState::markInstalled();
                return 0;
        }

        $this->error('Unknown step: ' . $this->argument('step'));
        return 1;
    }
}
