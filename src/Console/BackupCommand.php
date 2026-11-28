<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;
use Exceedone\Exment\Exceptions\BackupFailedException;

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

            $result = $this->backup->execute($target, $schedule);
            if ($result !== 0) {
                $this->logFailure(strval(exmtrans('backup.message.backup_error')));
                return 1;
            }
            return 0;
        } catch (BackupFailedException $e) {
            // the database dump failed, or no backup file was made: not a success
            $this->logFailure(strval(exmtrans('backup.message.backup_error')) . ' ' . $e->getMessage());
            return 1;
        } catch (BackupRestoreCheckException $e) {
            $this->logFailure($e->getMessage());
            return 1;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->backup->diskService()->deleteTmpDirectory();
        }
    }

    /**
     * A backup that failed: told, and kept in the log (a scheduled backup has no one to read
     * its output). The message has no password: the output of the client is masked when it is
     * read (MySqlClientTrait::dumpErrorDetail()).
     */
    protected function logFailure(string $message): void
    {
        $this->error($message);
        \Log::error('[Exment] backup failed: ' . $message);
    }
}
