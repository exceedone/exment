<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Model\System;
use Exceedone\Exment\Enums\BackupTarget;
use Exceedone\Exment\Services\Installer\EnvTrait;

class BackupCommand extends Command
{
    use CommandTrait, BackupRestoreTrait, EnvTrait;

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
            $target = $this->option("target") ?? BackupTarget::arrays();

            if (is_string($target)) {
                $target = collect(explode(",", $target))->map(function ($t) {
                    return new BackupTarget($t) ?? null;
                })->filter()->toArray();
            }
    
            $this->initBackupRestore();
    
            // backup database tables
            if (in_array(BackupTarget::DATABASE, $target)) {
                \DB::backupDatabase($this->diskService->tmpDiskItem()->dirFullPath());
            }
    
            // backup directory
            if (!$this->copyFiles($target)) {
                return -1;
            }
    
            // archive whole folder to zip
            $this->createZip();
    
            $this->removeOldBackups();
    
            return 0;
        }
        catch(\Exception $e){
            throw $e;
        }
        finally{
            $this->diskService->deleteTmpDirectory();
        }
    }

    /**
     * copy folder to temp directory
     *
     * @return bool true:success/false:fail
     */
    protected function copyFiles($target)
    {
        // get directory paths
        $settings = collect($target)->map(function ($val) {
            return BackupTarget::dirOrDisk($val);
        })->filter(function ($val) {
            return isset($val);
        })->toArray();
        
        foreach ($settings as $setting) {
            // is local file
            if(is_string($setting)){
                $from = base_path($setting);
                if (!\File::exists($from)) {
                    continue;
                }

                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting);
                
                if (!$this->tmpDisk()->exists($to)) {
                    $this->tmpDisk()->makeDirectory($to, 0755, true);
                }

                \File::copyDirectory($from, $this->tmpDisk()->path($to));
            }
            // is croud file
            else{
                $disk = $setting[0];
                
                $to = path_join($this->diskService->tmpDiskItem()->dirName(), $setting[1]);
                
                if (!$this->tmpDisk()->exists($to)) {
                    $this->tmpDisk()->makeDirectory($to, 0755, true);
                }

                $files = $disk->allFiles('');
                foreach ($files as $file) {
                    // copy from crowd to local
                    $stream = $disk->readStream($file);
                    $this->tmpDisk()->writeStream(path_join($to, $file), $stream);

                    fclose($stream);
                }
            }
        }
            
        // if contains 'config' in $settings, copy env file
        if (in_array('config', $settings)) {
            $envLines = $this->getMatchedEnv();
            $to_env = $this->tmpDisk()->path(path_join($this->diskService->tmpDiskItem()->dirName(), '.env'));

            \File::put($to_env, $envLines);
        }

        return true;
    }
    
    /**
     * archive whole folder(sql and tsv only) to zip.
     *
     */
    protected function createZip()
    {
        // open new zip file
        $zip = new \ZipArchive();
        $res = $zip->open($this->diskService->tmpDiskItem()->fileFullPath(), \ZipArchive::CREATE);

        if ($res === true) {
            // iterator all files in folder
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->diskService->tmpDiskItem()->dirFullPath()));
            foreach ($files as $name => $file) {
                if ($file->isDir()) {
                    continue;
                }
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($this->diskService->tmpDiskItem()->dirFullPath()) + 1);
                $zip->addFile($filePath, $relativePath);
            }
            $zip->close();
        }

        // upload file

        $uploadPaths = [
            $this->diskService->tmpDiskItem()->filePath() => $this->diskService->diskItem()->filePath()
        ];

        $this->diskService->upload($uploadPaths);
    }
    
    /**
     * Remove old backup
     *
     * @return void
     */
    protected function removeOldBackups()
    {
        // get history file counts
        $backup_history_files = System::backup_history_files();
        if (!isset($backup_history_files) || $backup_history_files <= 0) {
            return;
        }

        // check whether batch
        $schedule = boolval($this->option("schedule"));
        if (!$schedule) {
            return;
        }

        $disk = $this->disk();

        // get files
        $filenames = $disk->files($this->diskService->dirName());

        // get file infos
        $files = collect($filenames)->map(function ($filename) use ($disk) {
            return [
                'name' => $filename,
                'lastModified' => $disk->lastModified($filename),
            ];
        })->sortByDesc('lastModified');

        // remove file
        foreach ($files->values()->all() as $index => $file) {
            if ($index < $backup_history_files) {
                continue;
            }

            $disk->delete(array_get($file, 'name'));
        }
    }
    
    /**
     * get matched env data
     *
     */
    protected function getMatchedEnv()
    {
        // get env file
        $file = path_join(base_path(), '.env');
        if (!\File::exists($file)) {
            return null;
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

        $results = [];
        foreach ($matchKeys as $item) {
            foreach ($item['keys'] as $key) {
                if (is_null($lines = $this->getEnv($key, $file, $item['prefix']))) {
                    continue;
                }

                $results = array_merge(collect($lines)->map(function($line){
                    return "{$line[0]}={$line[1]}";
                })->toArray(), $results);
            }
        }

        return implode("\n", $results);
    }

}
