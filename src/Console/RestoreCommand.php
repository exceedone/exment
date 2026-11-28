<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\InvalidZipEntryException;
use Exceedone\Exment\Exceptions\RestoreFailedException;

class RestoreCommand extends Command
{
    use CommandTrait;
    use EnvTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:restore {file?} {--tmp=} {--yes}';

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
        $message = preg_replace('/<br>/u', '', exmtrans('backup.message.restore_caution'));

        if (!boolval($this->option('yes')) && !$this->confirm($message)) {
            return 1;
        }

        $result = 0;

        try {
            $this->restore->initBackupRestore();

            $file = $this->getFile();
            $tmp = boolval($this->option("tmp"));

            // a backup file that is not there: said before anything starts (the site is not put
            // into maintenance), instead of an error from the disk in the middle of the restore
            if (!$this->backupFileExists(strval($file), $tmp)) {
                $this->error(strval(exmtrans('backup.message.restore_file_not_found', ['name' => strval($file)])));
                return 1;
            }

            $result = $this->restore->execute($file, $tmp);

            if ($this->restore->isConfigSkipped()) {
                $this->warn(exmtrans('backup.message.restore_config_skipped'));
            }
        } catch (BackupRestoreCheckException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (RestoreFailedException $e) {
            // stopped: before the database was changed (nothing was restored), or midway
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
            $this->restore->diskService()->deleteTmpDirectory();
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
