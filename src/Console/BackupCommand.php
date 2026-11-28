<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupFailedException;
use Exceedone\Exment\Exceptions\BackupRunLockedException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;

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

    /**
     * @var \Exceedone\Exment\Services\BackupRestore\Backup
     */
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
        $run = null;
        try {
            $target = $this->option("target") ?? BackupTarget::arrays();
            // @phpstan-ignore-next-line
            $schedule = boolval($this->option("schedule") ?? false);

            if (is_string($target)) {
                $target = collect(explode(",", $target))->map(function ($t) {
                    // @phpstan-ignore-next-line
                    return new BackupTarget($t) ?? null;
                })->filter()->toArray();
            }

            $this->backup->initBackupRestore();

            // the run is recorded as the ones of the screen (ISS02943): the history, the lock, the log
            $run = BackupRuns::start(BackupRun::KIND_BACKUP, $schedule ? BackupRun::TRIGGER_SCHEDULE : BackupRun::TRIGGER_COMMAND, [
                'targets' => is_array($target) ? $target : [$target],
            ]);
            $this->info(strval(exmtrans('backup.run.message.cli_started', ['kind' => exmtrans('backup.run.kinds.backup'), 'uuid' => $run->uuid])));

            $result = BackupRuns::runBackup($run, $target, $schedule, function (string $line) {
                $this->line($line);
            });
            $this->line(strval(exmtrans('backup.run.message.cli_log', ['path' => BackupRuns::logRelativePath($run)])));

            if ($result !== 0) {
                $this->error(strval(exmtrans('backup.message.backup_error')));
                return 1;
            }
            return 0;
        } catch (BackupFailedException $e) {
            // the database dump failed, or no backup file was made: not a success
            $this->error(strval(exmtrans('backup.message.backup_error')) . ' ' . $e->getMessage());
            if (!is_null($run)) {
                $this->line(strval(exmtrans('backup.run.message.cli_log', ['path' => BackupRuns::logRelativePath($run)])));
            }
            return 1;
        } catch (BackupRunLockedException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (BackupRestoreCheckException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->backup->diskService()?->deleteTmpDirectory();
        }
    }
}
