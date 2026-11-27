<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Exceptions\BackupRunLockedException;
use Exceedone\Exment\Model\BackupRun;
use Exceedone\Exment\Services\BackupRestore\BackupRuns;

class RestoreCommand extends Command
{
    use CommandTrait;
    use EnvTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:restore {file?} {--tmp=} {--yes} {--backup-first : back up the data now before it is replaced} {--run= : the uuid of a restore asked on the screen, run apart}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore database definition, table data, files in selected folder';

    // @phpstan-ignore-next-line
    protected $restore;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();

        $this->initExmentCommand();

        $this->restore = new BackupRestore\Restore();
    }

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        // a restore asked on the screen, started apart (BackupRuns::launchRestore)
        if (!is_nullorempty($this->option('run'))) {
            return $this->handleRun(strval($this->option('run')));
        }

        $message = preg_replace('/<br>/u', '', exmtrans('backup.message.restore_caution'));

        if (!boolval($this->option('yes')) && !$this->confirm($message)) {
            return 1;
        }

        $result = 0;

        try {
            $this->restore->initBackupRestore();

            $file = $this->getFile();
            $tmp = boolval($this->option("tmp"));

            if (!$this->backupFileExists(strval($file), $tmp)) {
                $this->error(strval(exmtrans('backup.message.restore_file_not_found', ['name' => strval($file)])));
                return 1;
            }

            // the run is recorded as the ones of the screen (ISS02943): the history, the lock, the log
            $run = BackupRuns::start(BackupRun::KIND_RESTORE, BackupRun::TRIGGER_COMMAND, [
                'file_name' => is_string($file) ? pathinfo($file, PATHINFO_FILENAME) : null,
            ]);
            $this->info(strval(exmtrans('backup.run.message.cli_started', ['kind' => exmtrans('backup.run.kinds.restore'), 'uuid' => $run->uuid])));

            $result = BackupRuns::runRestore($run, $this->restore, $file, $tmp, function (string $line) {
                $this->line($line);
            }, boolval($this->option('backup-first')));
            $this->line(strval(exmtrans('backup.run.message.cli_log', ['path' => BackupRuns::logRelativePath($run)])));

            if ($this->restore->isConfigSkipped()) {
                $this->warn(exmtrans('backup.message.restore_config_skipped'));
            }
        } catch (BackupRunLockedException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (BackupRestoreCheckException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (InvalidZipEntryException $e) {
            // a refused backup zip is a bad input file, not a crash: say so and stop.
            // render() cannot help here, the console never renders a response.
            $this->error($e->getMessage());
            return 1;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->restore->diskService()?->deleteTmpDirectory();
        }

        // execute() returns non-zero when a step went wrong (a folder that could not be
        // copied, a schema that could not be migrated): told to the caller (cron, scripts)
        if ($result !== 0) {
            $this->error('Restore finished with errors. The data was restored, but see the log for the steps that failed.');
            return 1;
        }

        return 0;
    }

    /**
     * Run the restore of a run the screen started (queued): its file is in the run.
     */
    protected function handleRun(string $uuid): int
    {
        $run = BackupRuns::takeQueued($uuid, BackupRun::KIND_RESTORE);
        if (!isset($run)) {
            $this->error('The restore is not waiting: ' . $uuid);
            return 1;
        }
        $targets = array_values($run->targets ?? []);
        $tmp = ($targets[0] ?? null) === BackupRuns::SOURCE_UPLOAD;
        $file = strval($targets[1] ?? '');
        \Exment::setTimeLimitLong();
        ignore_user_abort(true);

        try {
            $this->restore->initBackupRestore();
            $result = BackupRuns::runRestore($run, $this->restore, $file, $tmp, function (string $line) {
                $this->line($line);
            }, in_array(BackupRuns::BACKUP_FIRST, $targets, true));
        } catch (\Throwable $e) {
            // told in the run (its log, the bell and the mail)
            $this->error(\Exceedone\Exment\Services\BackupRestore\SecretMasker::mask($e->getMessage()));
            return 1;
        } finally {
            $this->restore->diskService()?->deleteTmpDirectory();
        }

        // the uploaded file is consumed (kept when the restore failed, to try again)
        if ($result === 0 && $tmp && $file !== '') {
            try {
                \Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_ADMIN_TMP)->delete($file);
            } catch (\Throwable $e) {
                // nothing more
            }
        }
        return $result === 0 ? 0 : 1;
    }

    /**
     * Whether the backup file is there: a stored backup (list/<name>.zip), or with --tmp a file of
     * the temporary folder.
     */
    protected function backupFileExists(string $file, bool $tmp): bool
    {
        if ($file === '') {
            return false;
        }
        try {
            if ($tmp) {
                return \Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_ADMIN_TMP)->exists($file);
            }
            $name = pathinfo($file, PATHINFO_FILENAME);
            return \Storage::disk(\Exceedone\Exment\Model\Define::DISKNAME_BACKUP_SYNC)->exists(path_join('list', $name . '.zip'));
        } catch (\Throwable $e) {
            return false;
        }
    }

    // @phpstan-ignore-next-line
    protected function getFile()
    {
        $file = $this->argument("file");

        if (!is_nullorempty($file)) {
            return $file;
        }

        // get backup file list
        $list = $this->restore->list();

        if (count($list) == 0) {
            $this->info('Backup file not found.');
        }

        $file = $this->choice('Please choice backup file.', collect($list)->pluck('file_name')->toArray());

        return $file;
    }
}
