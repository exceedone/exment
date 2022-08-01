<?php

namespace Exceedone\Exment\Console;

use Illuminate\Console\Command;
use Exceedone\Exment\Services\DataImportExport;

class FileColumnImportCommand extends Command
{
    use CommandTrait;
    use ImportTrait;

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'exment:file-import {dir}';

    protected $directory;

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'File Import Exment data';


    protected static $actionClassName = DataImportExport\Actions\Import\FileColumnAction::class;

    protected static $directoryName = 'file-import';

    protected static $files_name = 'files';

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
     * @return int
     */
    public function handle()
    {
        $resultCode = 0;
        try {
            // get target directory (argument)
            $dir = $this->argument("dir");

            // check if parameter directory include separater
            if (preg_match('/[\\\\\/]/', $dir)) {
                throw new \Exception('parameter directory error : ' . $dir);
            }

            // get directory full path
            $this->directory = storage_path(path_join('app', static::$directoryName, $dir));

            // get file directory full path
            $fileDirectory = path_join($this->directory, static::$files_name);

            if (!is_dir($fileDirectory)) {
                throw new \Exception('Directory not found : ' . $fileDirectory);
            }

            // get all csv file names in target directory
            $files = $this->getFiles('csv,xlsx');
            if (count($files) == 0) {
                throw new \Exception('File not found : ' . $this->directory);
            }

            $this->line(exmtrans('command.import.file_count').count($files));

            foreach ($files as $index => $file) {
                $file_name = $file->getFileName();

                $this->line(($index + 1) . exmtrans('command.import.file_info', $file_name));

                $format = file_ext($file_name);

                $custom_table = $this->getTableFromFile($file_name);
                if (!isset($custom_table)) {
                    $this->error(exmtrans('command.import.error_info') . exmtrans('command.import.error_table', $file_name));
                    $resultCode = -1;
                    continue;
                }

                $service = (new DataImportExport\DataImportExportService())
                    ->filebasename($file_name)
                    ->importAction(new static::$actionClassName(
                        [
                            'fileDirFullPath' => $fileDirectory,
                            'custom_table' => $custom_table,
                        ]
                    ))
                    ->format($format);

                // Execute import. Show message executes in service.
                $result = $service->importBackground($this, $file_name, $file->getRealPath(), [
                    'checkCount' => false,  // whether checking count
                ]);

                if (boolval($result['result'] ?? true)) {
                    $this->line(($index + 1) . exmtrans('command.import.success_message', $file_name, array_get($result, 'data_import_cnt')));
                } else {
                    return $resultCode;
                }
            }
        } catch (\Exception $e) {
            \Log::error($e);
            $this->error($e->getMessage());
            return -1;
        }

        return $resultCode;
    }
}
