<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;

class BackupCommand extends Command
{
    use CommandTrait;
    use EnvTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:backup {--target=} {--schedule}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup database definition, table data, files in selected folder';

    protected $backup;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();

        $this->backup = new BackupRestore\Backup();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        try {
            $target = $this->option("target") ?? BackupTarget::arrays();
            /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
            $schedule = boolval($this->option("schedule") ?? false);

            if (is_string($target)) {
                $target = collect(explode(",", $target))->map(function ($t) {
                    /** @phpstan-ignore-next-line Expression on left side of ?? is not nullable. */
                    return new BackupTarget($t) ?? null;
                })->filter()->toArray();
            }

            $this->backup->initBackupRestore();

            return $this->backup->execute($target, $schedule);
        } catch (BackupRestoreCheckException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->backup->diskService()->deleteTmpDirectory();
        }
    }
}
