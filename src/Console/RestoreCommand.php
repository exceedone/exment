<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class RestoreCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:restore {file}';

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
        $path = $this->unzipFile($file);

        if (empty($path)) {
            return -1;
        }

        $result = 0;

        // restore table definition
        $this->restoreDatabase($path);

        // import tsv file to table
        $this->importTsv($path);

        // copy directory to temporary folder
        if (!$this->copyFiles($path)) {
            $result = -1;
        }

        // delete temporary folder
        $success = \File::deleteDirectory($path);

        return $result;
    }
    /**
     * insert table data from backup tsv files.
     * 
     * @param string unzip restore file path
     */
    private function importTsv($path)
    {
        // get tsv files in target folder
        $files = array_filter(\File::files($path), function ($file)
        {
            return preg_match('/.+\.tsv$/i', $file);
        });

        // load table data from tsv file
        foreach($files as $file) {
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
            $pathname = $file->getPathName();
            \Log::debug('$pathname:' . $pathname);
            \Log::debug('addslashes($pathname):' . addslashes($pathname));
            $query = sprintf($cmd, addslashes($file->getPathName()), $table);
            $cnt = \DB::connection()->getpdo()->exec($query);
        }
    }

    /**
     * copy folder from temp directory
     * 
     * @return bool true:success/false:fail
     */
    private function copyFiles($path)
    {
        $result = true;

        $directories = \File::directories($path);

        foreach($directories as $directory) {
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
     * 
     * @param string restore filename(no extension)
     */
    private function unzipFile($file)
    {
        // get backup folder full path
        $backup = Storage::disk('backup')->getAdapter()->getPathPrefix();
        // get temporary folder path
        $tempdir = path_join($backup, 'tmp', pathinfo($file, PATHINFO_FILENAME));

        // create temporary folder if not exists
        if (!is_dir($tempdir)) {
            mkdir($tempdir, 0755, true);
        }

        // open new zip file
        $zip = new \ZipArchive();
        if ($zip->open($file) === TRUE) {
            $zip->extractTo($tempdir);
            $zip->close();
            return $tempdir;
        }

        return NULL;
    }
    /**
     * restore backup table definition and table data.
     * 
     * @param string unzip folder path
     */
    private function restoreDatabase($path)
    {
        // get table connect info
        $host = config('database.connections.mysql.host', '');
        $username = config('database.connections.mysql.username', '');
        $password = config('database.connections.mysql.password', '');
        $database = config('database.connections.mysql.database', '');
        $dbport = config('database.connections.mysql.port', '');

        $mysqlcmd = sprintf('%s%s -h %s -u %s --password=%s -P %s %s', 
            config('exment.backup_info.mysql_dir'), 'mysql', 
            $host, $username, $password, $dbport, $database);

        // restore table definition
        $def = path_join($path, config('exment.backup_info.def_file'));
        if (\File::exists($def)) {
            $command = sprintf('%s < %s', $mysqlcmd, $def);
            exec($command);
            \File::delete($def);
        }

        // get insert sql file for each tables
        $files = array_filter(\File::files($path), function ($file)
        {
            return preg_match('/.+\.sql$/i', $file);
        });

        foreach($files as $file) {
            $command = sprintf('%s < %s', $mysqlcmd, $file->getRealPath());
            exec($command);
        }
    }
}
