<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use \File;

class RestoreCommand extends Command
{
    use CommandTrait, BackupRestoreTrait;

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

        // delete temporary folder
        $success = \File::deleteDirectory($this->tmpDirFullPath());

        return $result;
    }
    /**
     * insert table data from backup tsv files.
     *
     * @param string unzip restore file path
     */
    protected function importTsv()
    {
        // get tsv files in target folder
        $files = array_filter(\File::files($this->tmpDirFullPath()), function ($file) {
            return preg_match('/.+\.tsv$/i', $file);
        });

        // drop unused table
        $this->dropUnusedTable($files);

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
     * Drop unused "exm__" table
     *
     * @param [type] $files
     * @return void
     */
    protected function dropUnusedTable($files)
    {
        $fileTables = collect($files)->map(function ($file) {
            return $file->getBasename('.' . $file->getExtension());
        })->toArray();
        $exmTables = collect(\Schema::getTableListing())->filter(function ($table) use ($fileTables) {
            return stripos($table, 'exm__') === 0 && !in_array($table, $fileTables);
        })->flatten()->all();

        foreach ($exmTables as $table) {
            \Schema::dropIfExists($table);
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

        $directories = \File::directories($this->tmpDirFullPath());

        foreach ($directories as $directory) {
            $topath = base_path(mb_basename($directory));
            $success = \File::copyDirectory($directory, $topath);
            if (!$success) {
                $result = false;
            }
        }

        return $result;
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
        static::tmpDisk()->put($this->zipName(), $this->getRestoreZip());

        // create temporary folder if not exists
        if (!static::tmpDisk()->exists($this->tmpDirName())) {
            static::tmpDisk()->makeDirectory($this->tmpDirName(), 0755, true);
        }

        // open new zip file
        $zip = new \ZipArchive();
        if ($zip->open($this->zipFullPath()) === true) {
            $zip->extractTo($this->tmpDirFullPath());
            $zip->close();
        }

        static::tmpDisk()->delete($this->zipName());

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
        $def = path_join($this->tmpDirFullPath(), config('exment.backup_info.def_file'));
        if (\File::exists($def)) {
            $command = sprintf('%s < %s', $mysqlcmd, $def);
            exec($command);
            \File::delete($def);
        }

        // get insert sql file for each tables
        $files = array_filter(\File::files($this->tmpDirFullPath()), function ($file) {
            return preg_match('/.+\.sql$/i', $file);
        });

        foreach ($files as $file) {
            $command = sprintf('%s < %s', $mysqlcmd, $file->getRealPath());
            exec($command);
        }
    }

    /**
     * get restore zip info.
     * Change whether upload or not.
     *
     * @return mixed
     */
    protected function getRestoreZip()
    {
        // if get from tmp(upload file),
        if (boolval($this->option("tmp"))) {
            return static::tmpDisk()->get($this->zipName());
        }

        return static::disk()->get($this->listZipName());
    }
}
