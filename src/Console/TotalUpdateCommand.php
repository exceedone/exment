<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Exceptions\BackupFailedException;
use Exceedone\Exment\Services\Update\UpdateService;

class TotalUpdateCommand extends Command
{
    use CommandTrait;
    use InstallUpdateTrait;

    /**
     * The console command name.
     *
     * @var string
     */
    protected $signature = 'exment:total-update {--backup=1} {--publish=1}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Maintenance mode, backup and exment:update (run composer update first)';

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
        try {
            UpdateService::update([
                'backup' => boolval($this->option('backup') ?? true),
                'publish' => boolval($this->option('publish') ?? true),
                'output' => $this->output,
            ]);
        } catch (BackupFailedException $e) {
            // the backup failed: the update is not run
            $this->error(strval(exmtrans('backup.message.backup_error')) . ' ' . $e->getMessage());
            return 1;
        }
        return 0;
    }
}
