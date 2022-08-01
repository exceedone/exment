<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\BackupRestore;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Exceptions\BackupRestoreCheckException;

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

        try {
            $this->restore->initBackupRestore();

            $file = $this->getFile();
            $tmp = boolval($this->option("tmp"));

            $result = $this->restore->execute($file, $tmp);
        } catch (BackupRestoreCheckException $e) {
            $this->error($e->getMessage());
            return 1;
        } catch (\Exception $e) {
            throw $e;
        } finally {
            $this->restore->diskService()->deleteTmpDirectory();
        }
        return 0;
    }

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
