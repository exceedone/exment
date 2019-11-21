<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\Installer\EnvTrait;
use Exceedone\Exment\Model\System;
use \File;

class RestoreCommand extends Command
{
    use CommandTrait, BackupRestoreTrait, EnvTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:restore {file} {--tmp=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restore database definition, table data, files in selected folder';

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
     * @return mixed
     */
    public function handle()
    {
        try{
            $file = $this->argument("file");

            // unzip backup file
            $this->unzipFile($file);

            $result = 0;

            // restore table definition
            $this->restoreDatabase();

            // import tsv file to table
            $this->importTsv();

            // copy directory to temporary folder
            if (!$this->copyFiles()) {
                $result = -1;
            }

            // copy env
            $this->updateEnv();

            System::clearCache();
            
            return $result;
        }
        catch(\Exception $e){
            throw $e;
        }
        finally{
            $this->diskService->deleteTmpDirectory();
        }
    }
    /**
     * insert table data from backup tsv files.
     *
     * @param string unzip restore file path
     */
    protected function importTsv()
    {
        // get tsv files in target folder
        $files = array_filter(\File::files($this->diskService->tmpDiskItem()->dirFullPath()), function ($file) {
            return preg_match('/.+\.tsv$/i', $file);
        });

        // load table data from tsv file
        foreach ($files as $file) {
            $table = $file->getBasename('.' . $file->getExtension());
            $cmd =<<<__EOT__
            LOAD DATA local INFILE '%s' 
            INTO TABLE %s 
            CHARACTER SET 'UTF8' 
            FIELDS TERMINATED BY '\t' 
            OPTIONALLY ENCLOSED BY '\"' 
            ESCAPED BY '\"' 
            LINES TERMINATED BY '\\n' 
            IGNORE 1 LINES 
            SET created_at = nullif(created_at, '0000-00-00 00:00:00'),
                updated_at = nullif(updated_at, '0000-00-00 00:00:00'),
                deleted_at = nullif(deleted_at, '0000-00-00 00:00:00'),
                created_user_id = nullif(created_user_id, 0),
                updated_user_id = nullif(updated_user_id, 0),
                deleted_user_id = nullif(deleted_user_id, 0),
                parent_id = nullif(parent_id, 0)
__EOT__;
            $query = sprintf($cmd, addslashes($file->getPathName()), $table);
            $cnt = \DB::connection()->getpdo()->exec($query);
        }
    }

    /**
     * copy folder from temp directory
     *
     * @return bool true:success/false:fail
     */
    protected function copyFiles()
    {
        $result = true;
        $tmpDisk = $this->diskService->tmpDiskItem()->disk();

        $directories = $tmpDisk->allDirectories($this->diskService->tmpDiskItem()->dirName());
        
        foreach ($directories as $directory) {
            // check target key name
            $splits = explode("/", $directory);
            if(count($splits) != 2){
                continue;
            }
            $keyname = $splits[1];

            $setting = BackupTarget::dirOrDisk($keyname);
            if(is_null($setting)){
                continue;
            }
            
            $fromDirectory = $tmpDisk->path(path_join($this->diskService->tmpDiskItem()->dirName(), $keyname));
            // is local file
            if(is_string($setting)){
                $topath = base_path($setting);
                $success = \File::copyDirectory($fromDirectory, $topath);
                if (!$success) {
                    $result = false;
                }
            }
            // is croud file
            else{
                $disk = $setting[0];
                
                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);
                
                if (!$this->tmpDisk()->exists($to)) {
                    $this->tmpDisk()->makeDirectory($to, 0755, true);
                }

                $files = \File::allFiles($directory);
                foreach ($files as $file) {
                    // copy from crowd to local
                    $stream = \File::readStream($file);
                    $disk->writeStream(path_join($to, $file), $stream);
                }
            }
        }

        return $result;
    }
    
    /**
     * update env data
     *
     */
    protected function updateEnv()
    {
        // get env file
        $file = path_join($this->diskService->tmpDiskItem()->dirFullPath(), '.env');
        if (!\File::exists($file)) {
            return;
        }

        $matchKeys = [
            [
                'keys' => ['EXMENT_'],
                'prefix' => true,
            ],
            [
                'keys' => ['APP_KEY', 'APP_LOCALE', 'APP_TIMEZONE'],
                'prefix' => false,
            ],
        ];

        foreach ($matchKeys as $item) {
            foreach ($item['keys'] as $key) {
                if (is_null($lines = $this->getEnv($key, $file, $item['prefix']))) {
                    continue;
                }

                foreach ($lines as $line) {
                    $this->setEnv([$line[0] => $line[1]]);
                }
            }
        }
    }

    /**
     * unzip backup file to temporary folder path.
     */
    protected function unzipFile($file)
    {
        // get file
        $targetfile = array_get(pathinfo($file), 'filename');
        
        $this->initBackupRestore($targetfile);

        // set to tmp zip file
        if (!boolval($this->option("tmp"))) {
            $this->diskService->syncFromDisk();
        }

        return true;
    }
    /**
     * restore backup table definition and table data.
     *
     * @param string unzip folder path
     */
    protected function restoreDatabase()
    {
        // get all table list about "pivot_"
        collect(\Schema::getTableListing())->filter(function ($table) {
            return stripos($table, 'pivot_') === 0;
        })->each(function ($table) {
            \Schema::dropIfExists($table);
        });

        // get table connect info
        $host = config('database.connections.mysql.host', '');
        $username = config('database.connections.mysql.username', '');
        $password = config('database.connections.mysql.password', '');
        $database = config('database.connections.mysql.database', '');
        $dbport = config('database.connections.mysql.port', '');

        $mysqlcmd = sprintf(
            '%s%s -h %s -u %s --password=%s -P %s %s',
            config('exment.backup_info.mysql_dir'),
            'mysql',
            $host,
            $username,
            $password,
            $dbport,
            $database
        );

        // restore table definition
        $def = path_join($this->diskService->tmpDiskItem()->dirFullPath(), config('exment.backup_info.def_file'));
        if (\File::exists($def)) {
            $command = sprintf('%s < %s', $mysqlcmd, $def);
            exec($command);
            \File::delete($def);
        }

        // get insert sql file for each tables
        $files = array_filter(\File::files($this->diskService->tmpDiskItem()->dirFullPath()), function ($file) {
            return preg_match('/.+\.sql$/i', $file);
        });

        foreach ($files as $file) {
            $command = sprintf('%s < %s', $mysqlcmd, $file->getRealPath());
            exec($command);
        }
    }
}
