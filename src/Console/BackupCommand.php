<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Enums\BackupTarget;

class BackupCommand extends Command
{
    use CommandTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:backup {--target=}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup database definition, table data, files in selected folder';

    /**
     * console command start time (YmdHis)
     *
     * @var string
     */
    protected $starttime;

    /**
     * temporary folder path store files for archive
     *
     * @var string
     */
    protected $tempdir;

    /**
     * list folder path store backup files
     *
     * @var string
     */
    protected $listdir;

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $this->starttime = date('YmdHis');

        $target = $this->option("target") ?? BackupTarget::arrays();

        if(is_string($target)){
            $target = collect(explode(",", $target))->map(function($t){
                return new BackupTarget($t) ?? null;
            })->filter()->toArray();
        }

        $this->getBackupPath();

        // backup database tables
        if (in_array(BackupTarget::DATABASE, $target)) {
            $this->backupTables();
        }

        // backup directory
        if (!$this->copyFiles($target)) {
            return -1;
        }

        // archive whole folder to zip
        $this->createZip();

        // delete temporary folder
        $success = \File::deleteDirectory($this->tempdir);

        return 0;
    }

    /**
     * export table definition and table data
     * 
     */
    private function backupTables() 
    {
        // export table definition
        $this->dumpDatabase();

        // get all table list
        $tables = \DB::select('SHOW TABLES');

        // backup each table
        foreach($tables as $table)
        {
            foreach ($table as $key => $name)
            {
                if (stripos($name, 'exm__') === 0)
                {
                    // backup table data which has virtual column
                    $this->backupTable($name);
                } else {
                    // backup table data with mysqldump
                    $this->dumpDatabase($name);
                }
            }
        }
    }

    /**
     * backup table data except virtual generated column.
     * 
     * @param string backup target table
     */
    private function backupTable($table)
    {
        // create tsv file
        $file = new \SplFileObject(path_join($this->tempdir, $table.'.tsv'), 'w');
        $file->setCsvControl("\t");

        // get column definition
        $sql       = 'SHOW COLUMNS FROM '.$table;
        $columns   = \DB::select($sql);

        // get output field name list (not virtual column)
        $outcols = [];
        foreach ($columns as $column) {
            $array = array_change_key_case(((array)$column));
            if (strtoupper($array['extra']) != 'VIRTUAL GENERATED') {
                $outcols[] = strtolower($array['field']);
            }
        }
        // write column header
        $file->fputcsv($outcols);

        \DB::table($table)->orderBy('id')->chunk(100, function ($rows) use ($file, $outcols) {
            foreach ($rows as $row) {
                $array = (array)$row;
                $row = array_map(function($key) use ($array) {
                    return $array[$key];
                }, $outcols);
                // write detail data
                $file->fputcsv($row);
            }
        });
    }
    /**
     * get and create backup folder path
     * 
     */
    private function getBackupPath()
    {
        // edit temporary folder path for store archive file 
        $this->tempdir = storage_paths('app','backup','tmp', $this->starttime);
        // edit zip folder path 
        $this->listdir = storage_paths('app', 'backup', 'list');
        // create temporary folder if not exists
        if (!is_dir($this->tempdir)) {
            mkdir($this->tempdir, 0755, true);
        }
        // create zip folder if not exists
        if (!is_dir($this->listdir)) {
            mkdir($this->listdir, 0755, true);
        }
    }
    /**
     * copy folder to temp directory
     * 
     * @return bool true:success/false:fail
     */
    private function copyFiles($target)
    {
        // get directory paths
        $settings = collect($target)->map(function($val){
            return BackupTarget::dir($val);
        })->filter(function($val){
            return isset($val);
        })->toArray();
        $settings = array_merge(
            config('exment.backup_info.copy_dir', []),
            $settings
        );
        
        if (is_array($settings)) {
            foreach($settings as $setting) {
                $from = base_path($setting);
                $to = path_join($this->tempdir, $setting);
                
                $success = \File::copyDirectory($from, $to);

                if (!$success) {
                    return false;
                }
            }
        }
        return true;
    }
    /**
     * archive whole folder(sql and tsv only) to zip.
     * 
     */
    private function createZip()
    {
        // set last directory name to zipfile name
        $filename = $this->starttime . '.zip';

        // open new zip file
        $zip = new \ZipArchive();
        $res = $zip->open(path_join($this->listdir, $filename), \ZipArchive::CREATE);

        if ($res === TRUE) {
            // iterator all files in folder
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tempdir));
            foreach ($files as $name => $file)
            {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = substr($filePath, strlen($this->tempdir));
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();
        }
    }
    /**
     * exec mysqldump for backup table definition or table data.
     * 
     * @param string backup target table (default:null)
     */
    private function dumpDatabase($table=null)
    {
        // get table connect info
        $host = config('database.connections.mysql.host', '');
        $username = config('database.connections.mysql.username', '');
        $password = config('database.connections.mysql.password', '');
        $database = config('database.connections.mysql.database', '');
        $dbport = config('database.connections.mysql.port', '');

        $mysqldump = config('exment.backup_info.mysql_dir', '') . 'mysqldump';
        $command = sprintf('%s -h %s -u %s --password=%s -P %s', 
            $mysqldump, $host, $username, $password, $dbport);

        if ($table == null) {
            $file = path_join($this->tempdir , config('exment.backup_info.def_file', 'table_definition.sql'));
            $command = sprintf('%s -d %s > %s', $command, $database, $file);
        } else {
            $file = sprintf('%s.sql', path_join($this->tempdir, $table));
            $command = sprintf('%s -t %s %s > %s', $command, $database, $table, $file);
        }

        exec($command);
    }
}
